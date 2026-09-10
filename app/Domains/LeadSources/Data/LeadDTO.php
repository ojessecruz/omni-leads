<?php

namespace App\Domains\LeadSources\Data;

final readonly class LeadDTO
{
    /** @param  array<string, mixed>  $rawPayload */
    public function __construct(
        public string $driver,
        public string $externalId,
        public ?string $name,
        public ?string $companyName,
        public ?string $domain,
        public ?string $email,
        public ?string $phone,
        public string $country,
        public ?string $city,
        public ?string $cnpj,
        public array $rawPayload,
    ) {}
}
