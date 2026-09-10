<?php

namespace App\Domains\Enrichment\Data;

final readonly class EnrichmentResult
{
    /** @param  array<string, mixed>  $payload  gravado em lead_sources.raw_payload */
    public function __construct(
        public string $driver,
        public ?string $email = null,
        public ?string $phone = null,
        public ?bool $whatsappValid = null,
        public array $payload = [],
    ) {}
}
