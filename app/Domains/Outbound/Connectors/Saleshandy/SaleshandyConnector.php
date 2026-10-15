<?php

namespace App\Domains\Outbound\Connectors\Saleshandy;

use App\Domains\Outbound\Contracts\OutboundConnector;
use App\Domains\Outbound\Data\OutboundCampaign;
use App\Domains\Outbound\Data\OutboundEvent;
use App\Domains\Outbound\Data\OutboundProspect;
use App\Domains\Outbound\Exceptions\OutboundException;
use App\Enums\OutboundEventType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Saleshandy Open API (https://developer.saleshandy.com). Campaigns are sequences;
 * prospects go into the sequence's first step, identified by field name.
 */
class SaleshandyConnector implements OutboundConnector
{
    /** Saleshandy webhook event => ours. */
    public const array EVENTS = [
        'email-sent' => OutboundEventType::Sent,
        'reply-received' => OutboundEventType::Replied,
        'email-bounced' => OutboundEventType::Bounced,
        'prospect-unsubscribed' => OutboundEventType::Unsubscribed,
    ];

    /** Custom fields that carry the filled template (Saleshandy merge tags are {{Label}}). */
    public const array MESSAGE_FIELDS = [
        'subject' => ['label' => 'Omni Subject', 'fieldType' => 'text'],
        'message' => ['label' => 'Omni Message', 'fieldType' => 'long-text'],
    ];

    /** @var array<string, array<string, mixed>>|null sequences by id, fetched once per instance */
    private ?array $sequences = null;

    /** @param  array<string, mixed>  $config  outbound.connectors.saleshandy */
    public function __construct(private array $config, private string $apiKey) {}

    public function key(): string
    {
        return 'saleshandy';
    }

    public function messageTags(): array
    {
        return [
            'subject' => '{{'.self::MESSAGE_FIELDS['subject']['label'].'}}',
            'message' => '{{'.self::MESSAGE_FIELDS['message']['label'].'}}',
        ];
    }

    public function verify(): void
    {
        $this->send('get', '/sequences', ['pageSize' => 1]);
    }

    public function campaigns(): array
    {
        return array_values(array_map(fn (array $sequence) => new OutboundCampaign(
            id: (string) $sequence['id'],
            name: (string) ($sequence['title'] ?? $sequence['id']),
            active: (bool) ($sequence['active'] ?? true),
        ), $this->sequences()));
    }

    public function push(string $campaignId, array $prospects): string
    {
        $stepId = $this->sequences()[$campaignId]['steps'][0]['id'] ?? null;

        if (! is_scalar($stepId) || $stepId === '') {
            throw new OutboundException("Saleshandy sequence [{$campaignId}] was not found or has no steps.");
        }

        $this->ensureMessageFields();

        $response = $this->send('post', '/sequences/prospects/import-with-field-name', [
            'prospectList' => array_map($this->fields(...), $prospects),
            'stepId' => (string) $stepId,
            'verifyProspects' => true,
            // Never overwrite what the user edited in Saleshandy.
            'conflictAction' => 'addMissingFields',
            'tags' => ['Omni Leads'],
        ]);

        return (string) $response->json('payload.requestId', '');
    }

    public function registerWebhook(string $url): string
    {
        $response = $this->send('post', '/webhook/create', [
            'name' => 'Omni Leads',
            'webhookUrl' => $url,
            'webhookEvent' => array_keys(self::EVENTS),
            'headers' => (object) [],
        ]);

        $id = $response->json('payload.id');

        if (! is_scalar($id) || $id === '') {
            throw new OutboundException('Saleshandy created the webhook but returned no id.');
        }

        return (string) $id;
    }

    public function parseWebhook(array $payload): ?OutboundEvent
    {
        $type = self::EVENTS[$payload['event'] ?? ''] ?? null;
        $email = $payload['prospect']['email'] ?? null;

        if ($type === null || ! is_string($email) || $email === '') {
            return null;
        }

        // Each event names its own timestamp (emailSentAt, replyReceiveAt, ...).
        $occurredAt = null;
        foreach ($payload as $key => $value) {
            if (str_ends_with($key, 'At') && is_string($value)) {
                $occurredAt = $value;
                break;
            }
        }

        $sequenceId = $payload['sequence']['id'] ?? null;

        return new OutboundEvent(
            type: $type,
            email: mb_strtolower(trim($email)),
            occurredAt: $occurredAt,
            campaignId: is_scalar($sequenceId) ? (string) $sequenceId : null,
        );
    }

    /** @return array<string, string> Saleshandy system field names; empty values are left out */
    private function fields(OutboundProspect $prospect): array
    {
        return array_filter([
            'Email' => $prospect->email,
            'First Name' => $prospect->firstName,
            'Last Name' => $prospect->lastName,
            'Company' => $prospect->company,
            'Phone Number' => $prospect->phone,
            'City' => $prospect->city,
            'Country' => $prospect->country,
            'Company Domain' => $prospect->website,
            self::MESSAGE_FIELDS['subject']['label'] => $prospect->subject,
            self::MESSAGE_FIELDS['message']['label'] => $prospect->message,
        ], fn (string $value) => $value !== '');
    }

    /** The import only accepts field names that exist, so the template fields are created on first use. */
    private function ensureMessageFields(): void
    {
        $fields = $this->send('get', '/fields', ['systemFields' => 'false'])->json('payload', []);
        $labels = array_map(fn ($f) => is_array($f) ? (string) ($f['label'] ?? '') : '', is_array($fields) ? $fields : []);

        foreach (self::MESSAGE_FIELDS as $field) {
            if (! in_array($field['label'], $labels, true)) {
                $this->send('post', '/fields', $field);
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function sequences(): array
    {
        if ($this->sequences !== null) {
            return $this->sequences;
        }

        $payload = $this->send('get', '/sequences', ['pageSize' => 1000, 'sortBy' => 'sequence.title', 'sort' => 'ASC'])->json('payload', []);

        $this->sequences = [];

        foreach (is_array($payload) ? $payload : [] as $sequence) {
            if (is_array($sequence) && is_scalar($sequence['id'] ?? null)) {
                $this->sequences[(string) $sequence['id']] = $sequence;
            }
        }

        return $this->sequences;
    }

    /** @param  array<string, mixed>  $data */
    private function send(string $method, string $path, array $data = []): Response
    {
        try {
            $response = $this->client()->{$method}($this->config['base_url'].$path, $data);
        } catch (ConnectionException $e) {
            throw new OutboundException('Saleshandy is unreachable: '.$e->getMessage(), previous: $e);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new OutboundException('Saleshandy rejected the API key.');
        }

        if ($response->failed()) {
            throw new OutboundException(sprintf('Saleshandy responded %d: %s', $response->status(), $response->json('message', $response->body())));
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        if (trim($this->apiKey) === '') {
            throw new OutboundException('The Saleshandy API key is empty.');
        }

        return Http::withHeaders(['x-api-key' => $this->apiKey])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout((int) ($this->config['timeout'] ?? 15));
    }
}
