<?php

namespace App\Domains\Outbound;

use App\Models\Lead;
use Illuminate\Support\Str;

/** Placeholders shared by the WhatsApp and e-mail templates: {name}, {first_name}, {company}, {city}, {website}. */
class MessageTemplate
{
    public const array PLACEHOLDERS = ['{name}', '{first_name}', '{company}', '{city}', '{website}'];

    /** pt/es aliases keep working in older templates. */
    private const array ALIASES = [
        '{nome}' => '{name}', '{primeiro_nome}' => '{first_name}', '{empresa}' => '{company}', '{cidade}' => '{city}', '{site}' => '{website}',
        '{nombre}' => '{name}', '{primer_nombre}' => '{first_name}', '{ciudad}' => '{city}', '{sitio}' => '{website}',
    ];

    /** Fills the placeholders; line breaks are kept, repeated spaces collapsed. */
    public static function fill(string $template, Lead $lead): string
    {
        $name = $lead->name ?: $lead->company_name ?: '';
        $company = $lead->company_name ?: $lead->name ?: __('your company');

        $text = strtr(strtr($template, self::ALIASES), [
            '{name}' => $name,
            '{first_name}' => Str::before(trim($name), ' ') ?: __('there'),
            '{company}' => $company,
            '{city}' => $lead->city ?: __('your city'),
            '{website}' => $lead->websiteUrl() ?? '',
        ]);

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $text));
    }
}
