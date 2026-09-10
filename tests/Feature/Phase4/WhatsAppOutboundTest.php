<?php

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\Outbound\WhatsAppTemplate;
use App\Enums\LeadStatus;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\SendWhatsAppJob;
use App\Livewire\Leads\Show;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('enrichment.evolution', ['enabled' => true, 'base_url' => 'https://evo.test', 'instance' => 'main', 'key' => 'ek', 'requests_per_minute' => 100]);
});

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
    $lead = Lead::factory()->for(Icp::factory()->for($project))->create(['phone' => '5511999990000', 'status' => LeadStatus::Qualified]);

    (new SendWhatsAppJob($lead->id, 'Olá!', 'd-1'))->handle(app(EvolutionClient::class));

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
    $lead = Lead::factory()->create(['phone' => '5511999990000']);

    $job = new SendWhatsAppJob($lead->id, 'Olá!', 'd-1');
    $job->handle(app(EvolutionClient::class));
    $job->handle(app(EvolutionClient::class));

    Http::assertSentCount(1);
    expect($job->tries)->toBe(1)
        ->and($lead->sources()->where('driver', 'whatsapp_outbound')->count())->toBe(1);
});

test('SendWhatsAppJob registra o erro quando o envio falha e não marca contatado', function () {
    Queue::fake([DeliverWebhookJob::class]);
    Http::fake(['https://evo.test/message/sendText/main' => Http::response('down', 503)]);
    $other = Lead::factory()->create(['phone' => '5511999990001']);
    $failing = new SendWhatsAppJob($other->id, 'Oi', 'd-2');

    expect(fn () => $failing->handle(app(EvolutionClient::class)))->toThrow(Exception::class);
    $failing->failed(new RuntimeException('boom'));

    $source = $other->sources()->where('driver', 'whatsapp_outbound')->sole();
    expect($source->raw_payload['error'])->toBe('boom')
        ->and(isset($source->raw_payload['response']))->toBeFalse()
        ->and($other->fresh()->status)->toBe(LeadStatus::New);
});

test('detalhe do lead mostra o botão só com Evolution configurado e enfileira o envio', function () {
    Queue::fake();
    $lead = Lead::factory()->create(['phone' => '5511999990000', 'name' => 'Ana Silva']);

    Livewire::actingAs($lead->project->user)->test(Show::class, ['lead' => $lead])
        ->assertSee('Send WhatsApp')
        ->assertSet('whatsappText', WhatsAppTemplate::render(null, $lead))
        ->set('whatsappText', 'Oi Ana')
        ->call('sendWhatsApp')
        ->assertHasNoErrors();

    Queue::assertPushed(SendWhatsAppJob::class, fn ($j) => $j->leadId === $lead->id && $j->text === 'Oi Ana');

    config()->set('enrichment.evolution.enabled', false);
    Livewire::actingAs($lead->project->user)->test(Show::class, ['lead' => $lead])->assertDontSee('Send WhatsApp');
});
