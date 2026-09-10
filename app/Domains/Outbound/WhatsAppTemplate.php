<?php

namespace App\Domains\Outbound;

use App\Models\Lead;
use Illuminate\Support\Str;

/** Placeholders: {name}, {first_name}, {company}, {city}, {website}. */
class WhatsAppTemplate
{
    public const array PLACEHOLDERS = ['{name}', '{first_name}', '{company}', '{city}', '{website}'];

    /** pt/es aliases keep working in older templates. */
    private const array ALIASES = [
        '{nome}' => '{name}', '{primeiro_nome}' => '{first_name}', '{empresa}' => '{company}', '{cidade}' => '{city}', '{site}' => '{website}',
        '{nombre}' => '{name}', '{primer_nombre}' => '{first_name}', '{ciudad}' => '{city}', '{sitio}' => '{website}',
    ];

    public static function default(): string
    {
        return __('Hi {first_name}! I came across {company} in {city} and thought it made sense to reach out. Can I send you two lines about what we do?');
    }

    public static function render(?string $template, Lead $lead): string
    {
        $template = strtr(filled($template) ? $template : self::default(), self::ALIASES);
        $name = $lead->name ?: $lead->company_name ?: '';
        $company = $lead->company_name ?: $lead->name ?: __('your company');

        $text = strtr($template, [
            '{name}' => $name,
            '{first_name}' => Str::before(trim($name), ' ') ?: __('there'),
            '{company}' => $company,
            '{city}' => $lead->city ?: __('your city'),
            '{website}' => $lead->websiteUrl() ?? '',
        ]);

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $text));
    }
}
