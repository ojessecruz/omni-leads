<?php

namespace App\Domains\Enrichment\Clients;

use App\Domains\Enrichment\Data\EnrichmentResult;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Step 3: Evolution API — checks whether the phone has WhatsApp.
 * POST {base}/chat/whatsappNumbers/{instance} { numbers: [...] } → [{ jid, exists, number }]
 */
class EvolutionClient
{
    /** @param  array<string, mixed>  $config */
    public function __construct(private array $config) {}

    /** @param  array<string, string>  $credentials  the user's server, instance and key (BYOK) */
    public function withCredentials(array $credentials): self
    {
        if (isset($credentials['base_url'])) {
            $credentials['base_url'] = rtrim($credentials['base_url'], '/');
        }

        return new self([...$this->config, ...$credentials]);
    }

    /** GET {base}/instance/connectionState/{instance}: fails on a wrong URL, instance or key. */
    public function verify(): void
    {
        $this->assertConfigured();

        $response = Http::acceptJson()
            ->withHeaders(['apikey' => $this->config['key']])
            ->connectTimeout(3)
            ->timeout(10)
            ->get($this->config['base_url'].'/instance/connectionState/'.$this->config['instance']);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('Evolution responded %d: %s', $response->status(), $response->json('response.message.0', $response->json('message', $response->body()))));
        }
    }

    public function enrich(Lead $lead): ?EnrichmentResult
    {
        if (! $lead->phone) {
            return null;
        }

        $this->assertConfigured();
        $this->throttle();

        $url = $this->config['base_url'].'/chat/whatsappNumbers/'.$this->config['instance'];

        $response = Http::acceptJson()
            ->withHeaders(['apikey' => $this->config['key']])
            ->connectTimeout(3)
            ->timeout(20)
            ->retry(2, 500, throw: false)
            ->post($url, ['numbers' => [$lead->phone]]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('Evolution responded %d: %s', $response->status(), $response->json('message', $response->body())));
        }

        $body = $response->json();
        $entry = is_array($body) && is_array($body[0] ?? null) ? $body[0] : [];
        $exists = array_key_exists('exists', $entry) ? (bool) $entry['exists'] : null;

        return new EnrichmentResult(
            driver: 'evolution',
            whatsappValid: $exists,
            payload: ['number' => $lead->phone, 'response' => $entry],
        );
    }

    /**
     * Sends a WhatsApp text. POST {base}/message/sendText/{instance} { number, text }
     *
     * @return array<string, mixed> API response
     */
    public function sendText(string $number, string $text): array
    {
        $this->assertConfigured();
        $this->throttle();

        $url = $this->config['base_url'].'/message/sendText/'.$this->config['instance'];

        $response = Http::acceptJson()
            ->withHeaders(['apikey' => $this->config['key']])
            ->connectTimeout(3)
            ->timeout(20)
            ->post($url, ['number' => $number, 'text' => $text]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('Evolution responded %d: %s', $response->status(), $response->json('message', $response->body())));
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    public function isConfigured(): bool
    {
        return filled($this->config['base_url'] ?? null) && filled($this->config['instance'] ?? null) && filled($this->config['key'] ?? null);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Evolution API is not configured (server URL, instance and API key).');
        }
    }

    private function throttle(): void
    {
        $rpm = max(1, (int) ($this->config['requests_per_minute'] ?? 60));

        while (! RateLimiter::attempt('enrichment:evolution:'.md5((string) $this->config['key']), $rpm, fn () => true, 60)) {
            usleep(250_000);
        }
    }
}
