<?php

namespace App\Jobs;

use App\Models\Webhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Signed delivery (HMAC). 5xx and connection failures retry with backoff; 4xx is a final failure.
 * Each delivery adds at most one failure to failures_count; at the limit the webhook is deactivated.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public const int DISABLE_AFTER_FAILURES = 20;

    /** @param  array<string, mixed>  $data */
    public function __construct(
        public int $webhookId,
        public string $event,
        public array $data,
        public string $deliveryId = '',
    ) {
        $this->deliveryId = $deliveryId ?: (string) Str::uuid();
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $webhook = Webhook::find($this->webhookId);

        if ($webhook === null || ! $webhook->active) {
            return;
        }

        $body = json_encode([
            'event' => $this->event,
            'delivery_id' => $this->deliveryId,
            'sent_at' => now()->toIso8601String(),
            'data' => $this->data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $signature = hash_hmac('sha256', (string) $body, $webhook->secret);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'User-Agent' => 'OmniLeads-Webhook/1.0',
            'X-OmniLeads-Event' => $this->event,
            'X-OmniLeads-Delivery' => $this->deliveryId,
            'X-OmniLeads-Signature' => 'sha256='.$signature,
        ])->connectTimeout(3)->timeout(15)->withBody((string) $body, 'application/json')->post($webhook->url);

        if ($response->successful()) {
            $webhook->update(['last_status' => $response->status(), 'last_delivered_at' => now(), 'failures_count' => 0]);

            return;
        }

        $webhook->update(['last_status' => $response->status()]);

        if ($response->clientError()) {
            $this->recordFailure($webhook);

            return;
        }

        throw new RuntimeException("Webhook {$webhook->id} responded {$response->status()}");
    }

    /** Called when attempts are exhausted (repeated 5xx or no connection). */
    public function failed(?Throwable $exception): void
    {
        $webhook = Webhook::find($this->webhookId);

        if ($webhook !== null) {
            $this->recordFailure($webhook);
        }
    }

    private function recordFailure(Webhook $webhook): void
    {
        $failures = $webhook->failures_count + 1;

        $webhook->update([
            'failures_count' => $failures,
            'active' => $failures < self::DISABLE_AFTER_FAILURES,
        ]);
    }
}
