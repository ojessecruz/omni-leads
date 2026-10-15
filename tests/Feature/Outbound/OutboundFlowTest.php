<?php

use App\Domains\Leads\LeadScorer;
use App\Domains\Outbound\EmailTemplate;
use App\Domains\Outbound\Exceptions\OutboundException;
use App\Enums\LeadStatus;
use App\Events\LeadStatusChanged;
use App\Jobs\PushLeadsToOutboundJob;
use App\Jobs\RecordOutboundEventJob;
use App\Livewire\Leads\Show;
use App\Livewire\Projects\Settings;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\OutboundConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const SH_API = 'https://open-api.saleshandy.com/v1';

function shSequences(): array
{
    return ['payload' => [['id' => 'SEQ1', 'title' => 'Clínicas SP', 'active' => true, 'steps' => [['id' => 'STEP1', 'name' => 'Step 1']]]]];
}

test('conectar valida a chave na ferramenta, guarda criptografada e carrega as campanhas', function () {
    Http::fake([SH_API.'/sequences*' => Http::response(shSequences())]);
    $project = Project::factory()->create();

    Livewire::actingAs($project->user)->test(Settings::class, ['project' => $project])
        ->set('connector', 'saleshandy')
        ->set('apiKey', 'sh-secret-key')
        ->call('connectOutbound')
        ->assertHasNoErrors()
        ->assertSet('apiKey', '')
        ->assertSet('campaigns', [['id' => 'SEQ1', 'name' => 'Clínicas SP', 'active' => true]])
        ->set('campaignId', 'SEQ1')
        ->set('autoPush', true)
        ->call('saveOutbound')
        ->assertHasNoErrors();

    $connection = $project->outboundConnection()->first();

    expect($connection->credentials['api_key'])->toBe('sh-secret-key')
        ->and(DB::table('outbound_connections')->value('credentials'))->not->toContain('sh-secret-key')
        ->and($connection->campaign_name)->toBe('Clínicas SP')
        ->and($connection->auto_push)->toBeTrue()
        // APP_URL is local in tests: the webhook is not even attempted
        ->and($connection->webhook_id)->toBeNull();

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/webhook/create'));
});

test('chave recusada não salva conexão e mostra o erro no campo', function () {
    Http::fake([SH_API.'/*' => Http::response(['message' => 'Unauthorized'], 401)]);
    $project = Project::factory()->create();

    Livewire::actingAs($project->user)->test(Settings::class, ['project' => $project])
        ->set('apiKey', 'errada')
        ->call('connectOutbound')
        ->assertHasErrors('apiKey');

    expect($project->outboundConnection()->exists())->toBeFalse();
});

test('com APP_URL público, conectar registra o webhook com o segredo na URL', function () {
    config()->set('app.url', 'https://omnileads.example.com');
    url()->forceRootUrl('https://omnileads.example.com');
    url()->forceScheme('https');
    Http::fake([
        SH_API.'/webhook/create' => Http::response(['payload' => ['id' => 'WH1']], 201),
        SH_API.'/sequences*' => Http::response(shSequences()),
    ]);
    $project = Project::factory()->create();

    Livewire::actingAs($project->user)->test(Settings::class, ['project' => $project])
        ->set('apiKey', 'sh-key')
        ->call('connectOutbound');

    $connection = $project->outboundConnection()->first();

    expect($connection->webhook_id)->toBe('WH1');
    Http::assertSent(fn (Request $r) => $r->url() === SH_API.'/webhook/create'
        && $r['webhookUrl'] === "https://omnileads.example.com/outbound/{$connection->id}/webhook/{$connection->webhook_secret}");
});

test('campanha precisa ser uma das carregadas e outro usuário não acessa', function () {
    Http::fake([SH_API.'/sequences*' => Http::response(shSequences())]);
    $connection = OutboundConnection::factory()->create(['campaign_id' => null]);

    Livewire::actingAs($connection->project->user)->test(Settings::class, ['project' => $connection->project])
        ->set('campaignId', 'INVENTADA')
        ->call('saveOutbound')
        ->assertHasErrors('campaignId');

    Livewire::actingAs(User::factory()->create())->test(Settings::class, ['project' => $connection->project])->assertForbidden();
});

test('desconectar remove a conexão', function () {
    Http::fake([SH_API.'/sequences*' => Http::response(shSequences())]);
    $connection = OutboundConnection::factory()->create();

    Livewire::actingAs($connection->project->user)->test(Settings::class, ['project' => $connection->project])
        ->call('disconnectOutbound');

    expect(OutboundConnection::count())->toBe(0);
});

test('push envia só leads com e-mail ainda não enviados e registra em lead_sources', function () {
    Http::fake([
        SH_API.'/fields*' => Http::response(['payload' => [['label' => 'Omni Subject'], ['label' => 'Omni Message']]]),
        SH_API.'/sequences/prospects/*' => Http::response(['payload' => ['requestId' => 'REQ1']], 201),
        SH_API.'/sequences*' => Http::response(shSequences()),
    ]);
    $connection = OutboundConnection::factory()->create();
    $connection->project->update(['email_subject_template' => 'Ideia para {company}', 'email_template' => "Oi {first_name},\n\nvi a {company} em {city}."]);
    $icp = Icp::factory()->for($connection->project)->create();
    $ready = Lead::factory()->for($icp)->create(['name' => 'Ana Souza', 'company_name' => 'Spa Ana', 'city' => 'Campinas', 'email' => 'ana@bela.com', 'status' => LeadStatus::Qualified]);
    Lead::factory()->for($icp)->create(['email' => null, 'status' => LeadStatus::Qualified]);
    Lead::factory()->for($icp)->create(['email' => 'novo@x.com', 'status' => LeadStatus::New]);

    (new PushLeadsToOutboundJob($connection->id))->handle();
    (new PushLeadsToOutboundJob($connection->id))->handle(); // re-run sends nothing

    Http::assertSentCount(3); // sequences lookup + fields lookup + one import
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'import-with-field-name')
        && count($r['prospectList']) === 1
        && $r['prospectList'][0]['Email'] === 'ana@bela.com'
        && $r['prospectList'][0]['Omni Subject'] === 'Ideia para Spa Ana'
        && $r['prospectList'][0]['Omni Message'] === "Oi Ana,\n\nvi a Spa Ana em Campinas.");

    $row = $ready->sources()->where('driver', 'outbound_saleshandy')->first();
    expect($row->external_id)->toBe('SEQ1')
        ->and($row->raw_payload['request_id'])->toBe('REQ1')
        ->and($row->raw_payload['subject'])->toBe('Ideia para Spa Ana')
        ->and($connection->fresh()->last_error)->toBeNull();
});

test('falha no push fica registrada na conexão', function () {
    Http::fake([SH_API.'/*' => Http::response(['message' => 'boom'], 500)]);
    $connection = OutboundConnection::factory()->create();
    Lead::factory()->for(Icp::factory()->for($connection->project))->create(['email' => 'a@b.com', 'status' => LeadStatus::Qualified]);

    $job = new PushLeadsToOutboundJob($connection->id);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    $job->failed(new OutboundException('Saleshandy responded 500: boom'));

    expect($connection->fresh()->last_error)->toBe('Saleshandy responded 500: boom');
});

test('lead qualificado com envio automático entra na fila; sem e-mail ou sem auto não', function () {
    Queue::fake([PushLeadsToOutboundJob::class]);
    $connection = OutboundConnection::factory()->create(['auto_push' => true]);
    $icp = Icp::factory()->for($connection->project)->create(['criteria' => ['keywords' => ['x'], 'countries' => ['BR']]]);
    $lead = Lead::factory()->for($icp)->create(['country' => 'BR', 'email' => 'a@b.com', 'whatsapp_valid' => true]);

    app(LeadScorer::class)->score($lead);

    Queue::assertPushed(PushLeadsToOutboundJob::class, fn ($j) => $j->connectionId === $connection->id && $j->leadIds === [$lead->id]);

    $connection->update(['auto_push' => false]);
    $other = Lead::factory()->for($icp)->create(['country' => 'BR', 'email' => 'c@d.com', 'whatsapp_valid' => true]);
    app(LeadScorer::class)->score($other);

    Queue::assertPushed(PushLeadsToOutboundJob::class, 1);
});

test('webhook com segredo errado é recusado; evento válido entra na fila', function () {
    Queue::fake([RecordOutboundEventJob::class]);
    $connection = OutboundConnection::factory()->create();
    $body = ['event' => 'reply-received', 'prospect' => ['email' => 'ana@bela.com'], 'sequence' => ['id' => 'SEQ1']];

    $this->postJson("/outbound/{$connection->id}/webhook/errado", $body)->assertUnauthorized();
    $this->postJson("/outbound/{$connection->id}/webhook/{$connection->webhook_secret}", ['event' => 'email-opened', 'prospect' => ['email' => 'a@b.com']])->assertOk();
    Queue::assertNothingPushed();

    $this->postJson("/outbound/{$connection->id}/webhook/{$connection->webhook_secret}", $body)->assertOk();

    Queue::assertPushed(RecordOutboundEventJob::class, fn ($j) => $j->event['type'] === 'replied' && $j->event['email'] === 'ana@bela.com');
});

test('eventos movem o status: enviado → contatado, resposta → respondeu, e nunca volta', function () {
    Event::fake([LeadStatusChanged::class]);
    $connection = OutboundConnection::factory()->create();
    $lead = Lead::factory()->for(Icp::factory()->for($connection->project))->create(['email' => 'Ana@Bela.com', 'status' => LeadStatus::Qualified]);
    Lead::factory()->create(['email' => 'ana@bela.com']); // same e-mail in another project

    $record = fn (string $type) => (new RecordOutboundEventJob($connection->id, ['type' => $type, 'email' => 'ana@bela.com', 'campaign_id' => 'SEQ1']))->handle();

    $record('sent');
    expect($lead->fresh()->status)->toBe(LeadStatus::Contacted);

    $record('replied');
    expect($lead->fresh()->status)->toBe(LeadStatus::Replied);

    $record('sent');
    $record('bounced');
    expect($lead->fresh()->status)->toBe(LeadStatus::Replied);

    Event::assertDispatched(LeadStatusChanged::class, 2);
    expect($lead->sources()->where('driver', 'outbound_saleshandy')->first()->raw_payload['events'])->toHaveCount(4);
});

test('página do lead envia para o conector quando há campanha e e-mail', function () {
    Queue::fake([PushLeadsToOutboundJob::class]);
    $connection = OutboundConnection::factory()->create();
    $lead = Lead::factory()->for(Icp::factory()->for($connection->project))->create(['email' => 'ana@bela.com']);

    Livewire::actingAs($connection->project->user)->test(Show::class, ['lead' => $lead])
        ->assertSee('Send to Saleshandy')
        ->call('pushToOutbound');

    Queue::assertPushed(PushLeadsToOutboundJob::class, fn ($j) => $j->leadIds === [$lead->id]);

    $noEmail = Lead::factory()->for(Icp::factory()->for($connection->project))->create(['email' => null]);
    Livewire::actingAs($connection->project->user)->test(Show::class, ['lead' => $noEmail])->assertDontSee('Send to Saleshandy');
});

test('template de e-mail salva no projeto e mostra as merge tags do conector', function () {
    Http::fake([SH_API.'/sequences*' => Http::response(shSequences())]);
    $connection = OutboundConnection::factory()->create();
    $project = $connection->project;

    Livewire::actingAs($project->user)->test(Settings::class, ['project' => $project])
        ->assertSet('emailSubject', '{company} + a quick idea')
        ->assertSee('{{Omni Message}}')
        ->set('emailSubject', 'Oi {primeiro_nome}')
        ->set('emailBody', '')
        ->call('saveEmailTemplate')
        ->assertHasErrors('emailBody')
        ->set('emailBody', 'Corpo para {empresa}')
        ->call('saveEmailTemplate')
        ->assertHasNoErrors();

    expect($project->fresh()->email_subject_template)->toBe('Oi {primeiro_nome}')
        ->and(EmailTemplate::render('Oi {primeiro_nome}', 'Corpo para {empresa}', Lead::factory()->make(['name' => 'Ana Souza', 'company_name' => 'Spa Ana'])))
        ->toBe(['subject' => 'Oi Ana', 'body' => 'Corpo para Spa Ana']);
});
