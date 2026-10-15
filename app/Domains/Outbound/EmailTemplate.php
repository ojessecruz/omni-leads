<?php

namespace App\Domains\Outbound;

use App\Models\Lead;

/** First cold email (subject + body); placeholders in MessageTemplate. */
class EmailTemplate
{
    public static function defaultSubject(): string
    {
        return __('{company} + a quick idea');
    }

    public static function defaultBody(): string
    {
        // One key per paragraph: translation keys cannot hold line breaks.
        return implode("\n\n", [
            __('Hi {first_name},'),
            __('I came across {company} in {city} and thought it made sense to reach out.'),
            __('Can I send you two lines about what we do?'),
        ]);
    }

    /** @return array{subject: string, body: string} */
    public static function render(?string $subject, ?string $body, Lead $lead): array
    {
        return [
            'subject' => MessageTemplate::fill(filled($subject) ? $subject : self::defaultSubject(), $lead),
            'body' => MessageTemplate::fill(filled($body) ? $body : self::defaultBody(), $lead),
        ];
    }
}
