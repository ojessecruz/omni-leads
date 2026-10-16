<?php

namespace App\Jobs;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\WhatsApp\WebhookParser;
use App\Domains\WhatsApp\WhatsAppInbox;
use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Events\LeadStatusChanged;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Outbound message through Evolution. Marks the lead as contacted (unless it already replied),
 * records it in lead_sources and adds it to the lead's WhatsApp conversation.
 *
 * Sending is a non-idempotent POST on Evolution's side, so the job does not retry by itself:
 * the lead_sources row (external_id = delivery id) is written before sending and, if it already
 * holds a response, a manual re-run does not send the message again.
 */
class SendWhatsAppJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $leadId, public string $text, public string $deliveryId = '')
    {
        $this->deliveryId = $deliveryId ?: (string) Str::uuid();
        $this->onQueue('default');
    }

    public function handle(EvolutionClient $evolution, WebhookParser $parser, WhatsAppInbox $inbox): void
    {
        $lead = Lead::find($this->leadId);

        if ($lead === null || ! $lead->phone) {
            return;
        }

        $source = $this->source($lead);

        if (isset($source->raw_payload['response'])) {
            return;
        }

        $user = $lead->project->user;
        $response = $evolution->withCredentials($user->credentialsFor(IntegrationProvider::Evolution))->sendText($lead->phone, $this->text);

        $source->update([
            'raw_payload' => [
                ...$source->raw_payload,
                'message_id' => $response['key']['id'] ?? null,
                'response' => $response,
                'sent_at' => now()->toIso8601String(),
            ],
        ]);

        $from = $lead->status;

        if (! in_array($from, [LeadStatus::Contacted, LeadStatus::Replied], true)) {
            $lead->update(['status' => LeadStatus::Contacted]);
            LeadStatusChanged::dispatch($lead, $from);
        }

        // Recorded last: the broadcast it fires makes open pages reload the lead, already contacted.
        // The message is out, so a failure to log it in the inbox must not fail (and never re-send) the job.
        try {
            if ($sent = $parser->sent($response, $this->text)) {
                $inbox->record($user, $sent, sentVia: 'omni');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $lead = Lead::find($this->leadId);

        if ($lead === null) {
            return;
        }

        $source = $this->source($lead);

        $source->update(['raw_payload' => [...$source->raw_payload, 'error' => $exception?->getMessage()]]);
    }

    private function source(Lead $lead): LeadSource
    {
        /** @var LeadSource $source */
        $source = $lead->sources()->firstOrCreate(
            ['driver' => 'whatsapp_outbound', 'external_id' => $this->deliveryId],
            ['raw_payload' => ['text' => $this->text, 'queued_at' => now()->toIso8601String()]],
        );

        return $source;
    }
}
