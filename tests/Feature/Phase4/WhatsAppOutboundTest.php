<?php

use App\Domains\Integrations\WhatsAppPairing;
use App\Domains\Outbound\WhatsAppTemplate;
use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\SendWhatsAppJob;
use App\Livewire\Leads\Index;
use App\Livewire\Leads\Show;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Webhook;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('enrichment.evolution.requests_per_minute', 100);
});

/** The owner's Evolution: https://evo.test, instance "main", key "ek" (factory defaults). */
function withEvolution(Lead $lead): Lead
{
    connectIntegration($lead->project->user, IntegrationProvider::Evolution);

    return $lead;
}

test('template renderiza placeholders com fallbacks', function () {
    $lead = Lead::factory()->make(['name' => 'Ana Paula Silva', 'company_name' => 'Spa Ana', 'city' => 'Moema', 'domain' => 'spaana.com.br']);

    expect(WhatsAppTemplate::render('Hi {first_name} from {company} in {city}: {website}', $lead))
        ->toBe('Hi Ana from Spa Ana in Moema: https://spaana.com.br');

    // Portuguese aliases remain valid
    expect(WhatsAppTemplate::render('Oi {primeiro_nome} da {empresa}', $lead))->toBe('Oi Ana da Spa Ana');

    $bare = Lead::factory()->make(['name' => null, 'company_name' => null, 'city' => null, 'domain' => null]);
    expect(WhatsAppTemplate::render(null, $bare))->toContain('your company')->toContain('your city');
});

test('SendWhatsAppJob envia pelo Evolution, registra fonte, marca contatado e dispara webhook', function () {
    Queue::fake([DeliverWebhookJob::class]);
    Http::fake(['https://evo.test/message/sendText/main' => Http::response(['key' => ['id' => 'MSG1'], 'status' => 'PENDING'])]);

    $project = Project::factory()->create();
    Webhook::factory()->for($project)->create(['events' => ['lead.contacted']]);
    $lead = withEvolution(Lead::factory()->for(Icp::factory()->for($project))->create(['phone' => '5511999990000', 'status' => LeadStatus::Qualified]));

    app()->call([new SendWhatsAppJob($lead->id, 'Olá!', 'd-1'), 'handle']);

    Http::assertSent(fn ($req) => $req->hasHeader('apikey', 'ek') && $req['number'] === '5511999990000' && $req['text'] === 'Olá!');

    $lead->refresh();
    $source = $lead->sources()->where('driver', 'whatsapp_outbound')->sole();
    expect($lead->status)->toBe(LeadStatus::Contacted)
        ->and($source->external_id)->toBe('d-1')
        ->and($source->raw_payload['message_id'])->toBe('MSG1');

    Queue::assertPushed(DeliverWebhookJob::class, fn ($j) => $j->event === 'lead.contacted' && $j->data['previous_status'] === 'qualified');
});

test('SendWhatsAppJob não reenvia a mesma entrega', function () {
    Queue::fake([DeliverWebhookJob::class]);
    Http::fake(['https://evo.test/message/sendText/main' => Http::response(['key' => ['id' => 'MSG1']])]);
    $lead = withEvolution(Lead::factory()->create(['phone' => '5511999990000']));

    $job = new SendWhatsAppJob($lead->id, 'Olá!', 'd-1');
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    Http::assertSentCount(1);
    expect($job->tries)->toBe(1)
        ->and($lead->sources()->where('driver', 'whatsapp_outbound')->count())->toBe(1);
});

test('SendWhatsAppJob registra o erro quando o envio falha e não marca contatado', function () {
    Queue::fake([DeliverWebhookJob::class]);
    Http::fake(['https://evo.test/message/sendText/main' => Http::response('down', 503)]);
    $other = withEvolution(Lead::factory()->create(['phone' => '5511999990001']));
    $failing = new SendWhatsAppJob($other->id, 'Oi', 'd-2');

    expect(fn () => app()->call([$failing, 'handle']))->toThrow(Exception::class);
    $failing->failed(new RuntimeException('boom'));

    $source = $other->sources()->where('driver', 'whatsapp_outbound')->sole();
    expect($source->raw_payload['error'])->toBe('boom')
        ->and(isset($source->raw_payload['response']))->toBeFalse()
        ->and($other->fresh()->status)->toBe(LeadStatus::New);
});

test('detalhe do lead mostra o botão só com Evolution configurado e enfileira o envio', function () {
    Queue::fake();
    $lead = Lead::factory()->create(['phone' => '5511999990000', 'name' => 'Ana Silva']);

    Livewire::actingAs($lead->project->user)->test(Show::class, ['lead' => $lead])->assertDontSee('Send WhatsApp');

    withEvolution($lead);

    Livewire::actingAs($lead->project->user)->test(Show::class, ['lead' => $lead->fresh()])
        ->assertSee('Send WhatsApp')
        ->assertSet('whatsappText', WhatsAppTemplate::render(null, $lead))
        ->set('whatsappText', 'Oi Ana')
        ->call('sendWhatsApp')
        ->assertHasNoErrors();

    Queue::assertPushed(SendWhatsAppJob::class, fn ($j) => $j->leadId === $lead->id && $j->text === 'Oi Ana');

});

test('lista de leads agenda o primeiro WhatsApp pela linha, com intervalo entre envios e sem agendar duas vezes', function () {
    Queue::fake();
    $this->freezeTime();
    $lead = Lead::factory()->create(['phone' => '5511999990000', 'name' => 'Ana Silva']);
    $second = Lead::factory()->create(['icp_id' => $lead->icp_id, 'phone' => '5511999990001']);
    withEvolution($lead);

    $page = Livewire::actingAs($lead->project->user)->test(Index::class)
        ->assertSeeHtml('openWhatsApp('.$lead->id.')')
        ->call('openWhatsApp', $lead->id)
        ->assertSet('whatsappLeadId', $lead->id)
        ->assertSet('whatsappText', WhatsAppTemplate::render(null, $lead))
        ->set('whatsappText', 'Oi Ana')
        ->call('sendWhatsApp')
        ->assertHasNoErrors()
        ->assertSet('whatsappLeadId', null)
        ->assertDontSeeHtml('openWhatsApp('.$lead->id.')');

    // The first one goes right away and the lead shows as scheduled.
    Queue::assertPushed(SendWhatsAppJob::class, fn ($j) => $j->leadId === $lead->id && $j->text === 'Oi Ana' && $j->delay === null);
    expect($lead->pendingWhatsAppSends()->count())->toBe(1);

    $page->call('openWhatsApp', $lead->id)->call('sendWhatsApp')->assertHasErrors('whatsappText');

    // The next one waits a random 2–6 min gap.
    $page->call('openWhatsApp', $second->id)->call('sendWhatsApp')->assertHasNoErrors();
    Queue::assertPushed(SendWhatsAppJob::class, fn ($j) => $j->leadId === $second->id
        && $j->delay instanceof DateTimeInterface
        && $j->delay->getTimestamp() >= now()->addMinutes(2)->getTimestamp()
        && $j->delay->getTimestamp() <= now()->addMinutes(6)->getTimestamp());
    Queue::assertPushed(SendWhatsAppJob::class, 2);
});

test('fila de primeiros contatos cheia recusa o envio', function () {
    Queue::fake();
    config()->set('whatsapp.first_contact.per_day', [0 => 1]);
    config()->set('whatsapp.first_contact.max_days_ahead', 0);
    $lead = withEvolution(Lead::factory()->create(['phone' => '5511999990000']));
    $second = Lead::factory()->create(['icp_id' => $lead->icp_id, 'phone' => '5511999990001']);

    Livewire::actingAs($lead->project->user)->test(Index::class)
        ->call('openWhatsApp', $lead->id)->call('sendWhatsApp')->assertHasNoErrors()
        ->call('openWhatsApp', $second->id)->call('sendWhatsApp')->assertHasErrors('whatsappText');

    Queue::assertPushed(SendWhatsAppJob::class, 1);
    expect($second->pendingWhatsAppSends()->exists())->toBeFalse();
});

test('fora do horário comercial o primeiro contato fica para a próxima abertura', function () {
    Queue::fake();
    config()->set('whatsapp.business_hours.enabled', true);
    $this->travelTo(CarbonImmutable::parse('2026-10-17 15:00', 'America/Sao_Paulo')); // Saturday
    $lead = withEvolution(Lead::factory()->create(['phone' => '5511999990000']));

    Livewire::actingAs($lead->project->user)->test(Index::class)
        ->call('openWhatsApp', $lead->id)->call('sendWhatsApp')->assertHasNoErrors();

    Queue::assertPushed(SendWhatsAppJob::class, fn ($j) => $j->delay->equalTo(CarbonImmutable::parse('2026-10-19 09:00', 'America/Sao_Paulo')));
});

test('envio que falha com o celular desconectado pausa o WhatsApp, cancela os agendados e volta ao parear', function () {
    Queue::fake([DeliverWebhookJob::class]);
    Http::fake([
        'https://evo.test/message/sendText/main' => Http::response(['status' => 500, 'error' => 'Internal Server Error', 'response' => ['message' => 'Connection Closed']], 500),
        'https://evo.test/instance/connectionState/main' => Http::sequence()
            ->push(['instance' => ['instanceName' => 'main', 'state' => 'close']])
            ->push(['instance' => ['instanceName' => 'main', 'state' => 'open']]),
        'https://evo.test/instance/fetchInstances*' => Http::response([['name' => 'main', 'ownerJid' => '5511988887777@s.whatsapp.net']]),
    ]);
    $lead = withEvolution(Lead::factory()->create(['phone' => '5511999990000']));
    $other = Lead::factory()->create(['icp_id' => $lead->icp_id, 'phone' => '5511999990001']);
    $other->sources()->create(['driver' => 'whatsapp_outbound', 'external_id' => 'd-2', 'raw_payload' => ['text' => 'Oi', 'scheduled_for' => now()->addMinutes(5)->toIso8601String()]]);
    $user = $lead->project->user;

    app()->call([new SendWhatsAppJob($lead->id, 'Oi', 'd-1'), 'handle']);

    $integration = $user->fresh()->integration(IntegrationProvider::Evolution);
    expect($integration->credentials)->not->toHaveKey('connected_at')
        ->and($integration->credentials['disconnected_at'])->not->toBeEmpty()
        ->and($user->fresh()->hasIntegration(IntegrationProvider::Evolution))->toBeFalse()
        ->and($lead->sources()->where('external_id', 'd-1')->sole()->raw_payload['error'])->toContain('Connection Closed')
        ->and($lead->fresh()->status)->toBe(LeadStatus::New)
        ->and($other->pendingWhatsAppSends()->exists())->toBeFalse();

    // Its job then runs without calling Evolution.
    app()->call([new SendWhatsAppJob($other->id, 'Oi', 'd-2'), 'handle']);
    expect($other->sources()->where('external_id', 'd-2')->sole()->raw_payload['cancelled'])->toBeTrue();
    Http::assertSentCount(2);

    $this->actingAs($user->fresh())->get(route('leads.index'))->assertOk()->assertSee(__('Reconnect'));

    // Paired again: the warning goes away and the warm-up starts over.
    app(WhatsAppPairing::class)->check($integration);
    expect($integration->fresh()->credentials)->not->toHaveKey('disconnected_at')->toHaveKey('connected_at');
    $this->actingAs($user->fresh())->get(route('leads.index'))->assertDontSee(__('Reconnect'));
});

test('lista de leads não abre o envio para lead de outro usuário', function () {
    $mine = Lead::factory()->create();
    $other = Lead::factory()->create(['phone' => '5511988887777']);

    expect(fn () => Livewire::actingAs($mine->project->user)->test(Index::class)->call('openWhatsApp', $other->id))
        ->toThrow(ModelNotFoundException::class);
});
