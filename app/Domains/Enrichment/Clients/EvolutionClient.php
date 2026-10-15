<?php

namespace App\Domains\Enrichment\Clients;

use App\Domains\Enrichment\Data\EnrichmentResult;
use App\Models\Lead;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Step 3: Evolution API — checks whether the phone has WhatsApp, and sends WhatsApp messages.
 * POST {base}/chat/whatsappNumbers/{instance} { numbers: [...] } → [{ jid, exists, number }]
 *
 * The user gives the server URL and global key; the app creates one instance per user
 * and pairs it with the user's phone by QR code (Settings > Integrations).
 */
class EvolutionClient
{
    /** @param  array<string, mixed>  $config */
    public function __construct(private array $config) {}

    /** @param  array<string, string>  $credentials  the user's server URL, global key and paired instance (BYOK) */
    public function withCredentials(array $credentials): self
    {
        if (isset($credentials['base_url'])) {
            $credentials['base_url'] = rtrim($credentials['base_url'], '/');
        }

        return new self([...$this->config, ...$credentials]);
    }

    /** GET {base}/instance/fetchInstances answers 401 unless the key is the server's global key. */
    public function verify(): void
    {
        $this->assertServer();

        $this->ensureOk($this->http()->get($this->config['base_url'].'/instance/fetchInstances'));
    }

    /**
     * Creates a WhatsApp (Baileys) instance and returns its first QR code, if already generated.
     *
     * @return string|null QR code as a data:image/png;base64 URI
     */
    public function createInstance(string $name): ?string
    {
        $this->assertServer();

        $response = $this->ensureOk($this->http()->timeout(30)->post($this->config['base_url'].'/instance/create', [
            'instanceName' => $name,
            'qrcode' => true,
            'integration' => 'WHATSAPP-BAILEYS',
        ]));

        return $this->qrFrom($response->json('qrcode.base64'));
    }

    /**
     * GET {base}/instance/connect/{instance}: a fresh QR code, or null when the phone is already paired.
     */
    public function qrCode(): ?string
    {
        $this->assertConfigured();

        $response = $this->ensureOk($this->http()->timeout(20)->get($this->config['base_url'].'/instance/connect/'.$this->config['instance']));

        return $this->qrFrom($response->json('base64'));
    }

    /** "open" once the phone is paired; "connecting" while waiting for the QR scan; "close" otherwise. */
    public function connectionState(): string
    {
        $this->assertConfigured();

        $response = $this->ensureOk($this->http()->get($this->config['base_url'].'/instance/connectionState/'.$this->config['instance']));

        return (string) ($response->json('instance.state') ?? 'close');
    }

    /** Paired phone number (digits only), read from the instance owner JID. */
    public function pairedNumber(): ?string
    {
        $this->assertConfigured();

        $response = $this->ensureOk($this->http()->get($this->config['base_url'].'/instance/fetchInstances', ['instanceName' => $this->config['instance']]));
        $jid = $response->json('0.ownerJid');

        return is_string($jid) && $jid !== '' ? strstr($jid, '@', true) ?: $jid : null;
    }

    /** Unpairs the phone; the instance stays on the server to be paired again. */
    public function logout(): void
    {
        $this->assertConfigured();

        $this->http()->delete($this->config['base_url'].'/instance/logout/'.$this->config['instance']);
    }

    public function deleteInstance(): void
    {
        $this->assertConfigured();

        $this->http()->delete($this->config['base_url'].'/instance/delete/'.$this->config['instance']);
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
            throw new RuntimeException('WhatsApp is not connected: pair a phone in Settings > Integrations.');
        }
    }

    private function assertServer(): void
    {
        if (blank($this->config['base_url'] ?? null) || blank($this->config['key'] ?? null)) {
            throw new RuntimeException('Evolution API is not configured (server URL and API key).');
        }
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->withHeaders(['apikey' => $this->config['key']])->connectTimeout(3)->timeout(10);
    }

    private function ensureOk(Response $response): Response
    {
        if ($response->failed()) {
            $message = $response->json('response.message.0') ?? $response->json('message') ?? $response->body();

            throw new RuntimeException(sprintf('Evolution responded %d: %s', $response->status(), is_string($message) ? $message : json_encode($message)));
        }

        return $response;
    }

    /** Only data:image URIs reach the page, so the QR can go straight into an <img src>. */
    private function qrFrom(mixed $base64): ?string
    {
        if (! is_string($base64) || $base64 === '') {
            return null;
        }

        return str_starts_with($base64, 'data:image/') ? $base64 : 'data:image/png;base64,'.$base64;
    }

    private function throttle(): void
    {
        $rpm = max(1, (int) ($this->config['requests_per_minute'] ?? 60));

        while (! RateLimiter::attempt('enrichment:evolution:'.md5((string) $this->config['key']), $rpm, fn () => true, 60)) {
            usleep(250_000);
        }
    }
}
