<?php

namespace App\Domains\WhatsApp\Data;

use App\Enums\WhatsAppMessageStatus;
use App\Enums\WhatsAppMessageType;

/** One WhatsApp message as Evolution reports it, already reduced to what the inbox stores. */
final readonly class WhatsAppMessageData
{
    public function __construct(
        public string $externalId,
        public string $remoteJid,
        public string $phone,
        public bool $fromMe,
        public WhatsAppMessageType $type,
        public ?string $body = null,
        public ?string $mimeType = null,
        public ?string $fileName = null,
        public ?string $pushName = null,
        public ?WhatsAppMessageStatus $status = null,
        public int $sentAt = 0,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            externalId: (string) $data['external_id'],
            remoteJid: (string) $data['remote_jid'],
            phone: (string) $data['phone'],
            fromMe: (bool) ($data['from_me'] ?? false),
            type: WhatsAppMessageType::tryFrom((string) ($data['type'] ?? '')) ?? WhatsAppMessageType::Other,
            body: $data['body'] ?? null,
            mimeType: $data['mime_type'] ?? null,
            fileName: $data['file_name'] ?? null,
            pushName: $data['push_name'] ?? null,
            status: WhatsAppMessageStatus::tryFrom((string) ($data['status'] ?? '')),
            sentAt: (int) ($data['sent_at'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'remote_jid' => $this->remoteJid,
            'phone' => $this->phone,
            'from_me' => $this->fromMe,
            'type' => $this->type->value,
            'body' => $this->body,
            'mime_type' => $this->mimeType,
            'file_name' => $this->fileName,
            'push_name' => $this->pushName,
            'status' => $this->status?->value,
            'sent_at' => $this->sentAt,
        ];
    }

    /** Short text for the conversation list. */
    public function preview(): string
    {
        $text = trim((string) $this->body);

        if ($this->type === WhatsAppMessageType::Text || ($text !== '' && $this->type !== WhatsAppMessageType::Other)) {
            return mb_strimwidth(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 120, '…');
        }

        return '['.$this->type->label().']';
    }
}
