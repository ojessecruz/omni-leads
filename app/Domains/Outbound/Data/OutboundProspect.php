<?php

namespace App\Domains\Outbound\Data;

use App\Models\Lead;
use Illuminate\Support\Str;

/** What a connector receives for each lead. */
final readonly class OutboundProspect
{
    public function __construct(
        public string $email,
        public string $firstName = '',
        public string $lastName = '',
        public string $company = '',
        public string $phone = '',
        public string $website = '',
        public string $city = '',
        public string $country = '',
    ) {}

    /**
     * The person's name only when the lead has one apart from the company
     * (a Places lead's name is the business, which makes a bad "Hi {first name}").
     */
    public static function fromLead(Lead $lead): self
    {
        $person = trim((string) $lead->name);
        $company = trim((string) ($lead->company_name ?: $lead->name));

        if ($person !== '' && mb_strtolower($person) === mb_strtolower($company)) {
            $person = '';
        }

        return new self(
            email: mb_strtolower(trim((string) $lead->email)),
            firstName: Str::before($person, ' '),
            lastName: str_contains($person, ' ') ? trim(Str::after($person, ' ')) : '',
            company: $company,
            phone: $lead->phone ? '+'.$lead->phone : '',
            website: (string) $lead->domain,
            city: (string) $lead->city,
            country: $lead->country,
        );
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $string = fn (string $key): string => is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';

        return new self(
            email: mb_strtolower($string('email')),
            firstName: $string('first_name'),
            lastName: $string('last_name'),
            company: $string('company'),
            phone: $string('phone'),
            website: $string('website'),
            city: $string('city'),
            country: $string('country'),
        );
    }

    /** @return array{email: string, first_name: string, last_name: string, company: string, phone: string, website: string, city: string, country: string} */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company' => $this->company,
            'phone' => $this->phone,
            'website' => $this->website,
            'city' => $this->city,
            'country' => $this->country,
        ];
    }
}
