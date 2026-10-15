<?php

namespace App\Domains\Outbound\Data;

use App\Enums\OutboundEventType;

/** Something the cold-mail tool reported about a prospect, matched to the lead by e-mail. */
final readonly class OutboundEvent
{
    public function __construct(
        public OutboundEventType $type,
        public string $email,
        public ?string $occurredAt = null,
        public ?string $campaignId = null,
    ) {}

    /**
     * Null when the type is unknown or the e-mail is missing.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $type = OutboundEventType::tryFrom((string) ($data['type'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));

        if ($type === null || $email === '') {
            return null;
        }

        return new self(
            type: $type,
            email: $email,
            occurredAt: filled($data['occurred_at'] ?? null) ? (string) $data['occurred_at'] : null,
            campaignId: filled($data['campaign_id'] ?? null) ? (string) $data['campaign_id'] : null,
        );
    }

    /** @return array{type: string, email: string, occurred_at: string|null, campaign_id: string|null} */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'email' => $this->email,
            'occurred_at' => $this->occurredAt,
            'campaign_id' => $this->campaignId,
        ];
    }
}
