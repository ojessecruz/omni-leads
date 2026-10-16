<?php

namespace App\Domains\WhatsApp\Data;

use App\Enums\WhatsAppMessageStatus;

final readonly class WhatsAppStatusUpdate
{
    public function __construct(
        public string $externalId,
        public WhatsAppMessageStatus $status,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): ?self
    {
        $status = WhatsAppMessageStatus::tryFrom((string) ($data['status'] ?? ''));

        return $status && filled($data['external_id'] ?? null) ? new self((string) $data['external_id'], $status) : null;
    }

    /** @return array{external_id: string, status: string} */
    public function toArray(): array
    {
        return ['external_id' => $this->externalId, 'status' => $this->status->value];
    }
}
