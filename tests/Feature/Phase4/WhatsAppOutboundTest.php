<?php

use App\Domains\Outbound\WhatsAppTemplate;
use App\Domains\WhatsApp\FirstContactLimit;
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

test('lista de leads envia o primeiro WhatsApp pela linha, com o template e o limite por hora', function () {
    Queue::fake();
    $lead = Lead::factory()->create(['phone' => '5511999990000', 'name' => 'Ana Silva', 'whatsapp_valid' => true]);
    $user = $lead->project->user;

    Livewire::actingAs($user)->test(Index::class)->assertDontSeeHtml('openWhatsApp('.$lead->id.')');

    withEvolution($lead);

    $page = Livewire::actingAs($user)->test(Index::class)
        ->assertSeeHtml('openWhatsApp('.$lead->id.')')
        ->call('openWhatsApp', $lead->id)
        ->assertSet('whatsappLeadId', $lead->id)
        ->assertSet('whatsappText', WhatsAppTemplate::render(null, $lead))
        ->set('whatsappText', 'Oi Ana')
        ->call('sendWhatsApp')
        ->assertHasNoErrors()
        ->assertSet('whatsappLeadId', null);

    Queue::assertPushed(SendWhatsAppJob::class, fn ($j) => $j->leadId === $lead->id && $j->text === 'Oi Ana');

    // Over the hourly cap of first contacts, nothing else is queued.
    foreach (range(2, FirstContactLimit::PER_HOUR) as $i) {
        app(FirstContactLimit::class)->attempt($user);
    }

    $page->call('openWhatsApp', $lead->id)->call('sendWhatsApp')->assertHasErrors('whatsappText');
    Queue::assertPushed(SendWhatsAppJob::class, 1);
});

test('lista de leads não abre o envio para lead de outro usuário', function () {
    $mine = Lead::factory()->create();
    $other = Lead::factory()->create(['phone' => '5511988887777']);

    expect(fn () => Livewire::actingAs($mine->project->user)->test(Index::class)->call('openWhatsApp', $other->id))
        ->toThrow(ModelNotFoundException::class);
});
