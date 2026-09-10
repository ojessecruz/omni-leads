<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Countries
{
    /** @var array<string, string> ISO-3166 alpha-2 => nome (pt-BR) */
    public const array ALL = [
        'BR' => 'Brasil', 'PT' => 'Portugal', 'US' => 'Estados Unidos', 'CA' => 'Canadá', 'MX' => 'México',
        'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colômbia', 'PE' => 'Peru', 'UY' => 'Uruguai',
        'PY' => 'Paraguai', 'BO' => 'Bolívia', 'EC' => 'Equador', 'VE' => 'Venezuela',
        'GB' => 'Reino Unido', 'IE' => 'Irlanda', 'ES' => 'Espanha', 'FR' => 'França', 'DE' => 'Alemanha',
        'IT' => 'Itália', 'NL' => 'Países Baixos', 'BE' => 'Bélgica', 'CH' => 'Suíça', 'AT' => 'Áustria',
        'SE' => 'Suécia', 'NO' => 'Noruega', 'DK' => 'Dinamarca', 'FI' => 'Finlândia', 'PL' => 'Polônia',
        'CZ' => 'Tchéquia', 'GR' => 'Grécia', 'RO' => 'Romênia', 'HU' => 'Hungria', 'LU' => 'Luxemburgo',
        'AU' => 'Austrália', 'NZ' => 'Nova Zelândia', 'JP' => 'Japão', 'KR' => 'Coreia do Sul', 'CN' => 'China',
        'IN' => 'Índia', 'SG' => 'Singapura', 'AE' => 'Emirados Árabes Unidos', 'IL' => 'Israel', 'TR' => 'Turquia',
        'ZA' => 'África do Sul', 'AO' => 'Angola', 'MZ' => 'Moçambique', 'CV' => 'Cabo Verde', 'NG' => 'Nigéria',
        'EG' => 'Egito', 'MA' => 'Marrocos', 'PH' => 'Filipinas', 'ID' => 'Indonésia', 'TH' => 'Tailândia',
        'VN' => 'Vietnã', 'MY' => 'Malásia', 'SA' => 'Arábia Saudita', 'PA' => 'Panamá', 'CR' => 'Costa Rica',
        'DO' => 'República Dominicana', 'GT' => 'Guatemala', 'CU' => 'Cuba', 'PR' => 'Porto Rico',
    ];

    /** @var array<string, string> ISO alpha-2 => English name (APIs such as Apollo filter by name) */
    public const array EN = [
        'BR' => 'Brazil', 'PT' => 'Portugal', 'US' => 'United States', 'CA' => 'Canada', 'MX' => 'Mexico',
        'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia', 'PE' => 'Peru', 'UY' => 'Uruguay',
        'PY' => 'Paraguay', 'BO' => 'Bolivia', 'EC' => 'Ecuador', 'VE' => 'Venezuela',
        'GB' => 'United Kingdom', 'IE' => 'Ireland', 'ES' => 'Spain', 'FR' => 'France', 'DE' => 'Germany',
        'IT' => 'Italy', 'NL' => 'Netherlands', 'BE' => 'Belgium', 'CH' => 'Switzerland', 'AT' => 'Austria',
        'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland', 'PL' => 'Poland',
        'CZ' => 'Czechia', 'GR' => 'Greece', 'RO' => 'Romania', 'HU' => 'Hungary', 'LU' => 'Luxembourg',
        'AU' => 'Australia', 'NZ' => 'New Zealand', 'JP' => 'Japan', 'KR' => 'South Korea', 'CN' => 'China',
        'IN' => 'India', 'SG' => 'Singapore', 'AE' => 'United Arab Emirates', 'IL' => 'Israel', 'TR' => 'Turkey',
        'ZA' => 'South Africa', 'AO' => 'Angola', 'MZ' => 'Mozambique', 'CV' => 'Cape Verde', 'NG' => 'Nigeria',
        'EG' => 'Egypt', 'MA' => 'Morocco', 'PH' => 'Philippines', 'ID' => 'Indonesia', 'TH' => 'Thailand',
        'VN' => 'Vietnam', 'MY' => 'Malaysia', 'SA' => 'Saudi Arabia', 'PA' => 'Panama', 'CR' => 'Costa Rica',
        'DO' => 'Dominican Republic', 'GT' => 'Guatemala', 'CU' => 'Cuba', 'PR' => 'Puerto Rico',
    ];

    /** @var array<string, string> ISO alpha-2 => nome em espanhol */
    public const array ES = [
        'BR' => 'Brasil', 'PT' => 'Portugal', 'US' => 'Estados Unidos', 'CA' => 'Canadá', 'MX' => 'México',
        'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia', 'PE' => 'Perú', 'UY' => 'Uruguay',
        'PY' => 'Paraguay', 'BO' => 'Bolivia', 'EC' => 'Ecuador', 'VE' => 'Venezuela',
        'GB' => 'Reino Unido', 'IE' => 'Irlanda', 'ES' => 'España', 'FR' => 'Francia', 'DE' => 'Alemania',
        'IT' => 'Italia', 'NL' => 'Países Bajos', 'BE' => 'Bélgica', 'CH' => 'Suiza', 'AT' => 'Austria',
        'SE' => 'Suecia', 'NO' => 'Noruega', 'DK' => 'Dinamarca', 'FI' => 'Finlandia', 'PL' => 'Polonia',
        'CZ' => 'Chequia', 'GR' => 'Grecia', 'RO' => 'Rumania', 'HU' => 'Hungría', 'LU' => 'Luxemburgo',
        'AU' => 'Australia', 'NZ' => 'Nueva Zelanda', 'JP' => 'Japón', 'KR' => 'Corea del Sur', 'CN' => 'China',
        'IN' => 'India', 'SG' => 'Singapur', 'AE' => 'Emiratos Árabes Unidos', 'IL' => 'Israel', 'TR' => 'Turquía',
        'ZA' => 'Sudáfrica', 'AO' => 'Angola', 'MZ' => 'Mozambique', 'CV' => 'Cabo Verde', 'NG' => 'Nigeria',
        'EG' => 'Egipto', 'MA' => 'Marruecos', 'PH' => 'Filipinas', 'ID' => 'Indonesia', 'TH' => 'Tailandia',
        'VN' => 'Vietnam', 'MY' => 'Malasia', 'SA' => 'Arabia Saudita', 'PA' => 'Panamá', 'CR' => 'Costa Rica',
        'DO' => 'República Dominicana', 'GT' => 'Guatemala', 'CU' => 'Cuba', 'PR' => 'Puerto Rico',
    ];

    private const array ALIASES = [
        'usa' => 'US', 'eua' => 'US', 'united states of america' => 'US', 'uk' => 'GB', 'england' => 'GB',
        'brasil' => 'BR', 'brazil' => 'BR', 'czech republic' => 'CZ', 'holland' => 'NL', 'the netherlands' => 'NL',
    ];

    public static function englishName(string $code): string
    {
        return self::EN[strtoupper($code)] ?? self::name($code);
    }

    /** Resolves a code from a code, pt-BR name, English name or alias. Returns null when unknown. */
    public static function code(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^[a-z]{2}$/i', $value)) {
            return strtoupper($value);
        }

        $key = mb_strtolower($value);

        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }

        foreach ([self::ALL, self::EN, self::ES] as $map) {
            foreach ($map as $code => $name) {
                if (mb_strtolower($name) === $key) {
                    return $code;
                }
            }
        }

        return null;
    }

    /** Name in the application's current locale. */
    public static function name(string $code): string
    {
        return self::localized()[strtoupper($code)] ?? strtoupper($code);
    }

    /** @return array<string, string> sorted by name in the current locale */
    public static function options(): array
    {
        $all = self::localized();
        $collator = class_exists(\Collator::class) ? new \Collator(app()->getLocale()) : null;

        uasort($all, fn (string $a, string $b): int => (int) ($collator ? $collator->compare($a, $b) : strcasecmp(Str::ascii($a), Str::ascii($b))));

        return $all;
    }

    /** @return array<string, string> */
    public static function localized(?string $locale = null): array
    {
        return match ($locale ?? app()->getLocale()) {
            'pt_BR', 'pt' => self::ALL,
            'es' => self::ES,
            default => self::EN,
        };
    }
}
