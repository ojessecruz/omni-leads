<?php

namespace App\Domains\Outbound\Data;

final readonly class OutboundCampaign
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $active = true,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            active: (bool) ($data['active'] ?? true),
        );
    }

    /** @return array{id: string, name: string, active: bool} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'active' => $this->active];
    }
}
