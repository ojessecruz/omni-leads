<?php

namespace App\Support;

final class Locales
{
    public const string DEFAULT = 'en';

    /** Where a guest's chosen locale is kept (logged-in users have `users.locale`). */
    public const string SESSION_KEY = 'locale';

    /** @var array<string, string> code => name in its own language */
    public const array SUPPORTED = [
        'en' => 'English',
        'pt_BR' => 'Português (Brasil)',
        'es' => 'Español',
    ];

    /** @var array<string, string> code => two-letter badge for the compact switcher */
    public const array SHORT = [
        'en' => 'EN',
        'pt_BR' => 'PT',
        'es' => 'ES',
    ];

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::SUPPORTED);
    }

    /** Best locale from the browser's Accept-Language header. */
    public static function fromAcceptLanguage(?string $header): ?string
    {
        if (! $header) {
            return null;
        }

        foreach (explode(',', $header) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0]));

            if (str_starts_with($tag, 'pt')) {
                return 'pt_BR';
            }
            if (str_starts_with($tag, 'es')) {
                return 'es';
            }
            if (str_starts_with($tag, 'en')) {
                return 'en';
            }
        }

        return null;
    }
}
