<?php

namespace App\Domains\Outbound;

use App\Models\Lead;

/** First WhatsApp message; placeholders in MessageTemplate. */
class WhatsAppTemplate
{
    public const array PLACEHOLDERS = MessageTemplate::PLACEHOLDERS;

    public static function default(): string
    {
        return __('Hi {first_name}! I came across {company} in {city} and thought it made sense to reach out. Can I send you two lines about what we do?');
    }

    public static function render(?string $template, Lead $lead): string
    {
        return MessageTemplate::fill(filled($template) ? $template : self::default(), $lead);
    }
}
