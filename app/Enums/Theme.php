<?php

namespace App\Enums;

/** Color theme of the signed-in app. The landing page and auth screens are always dark. */
enum Theme: string
{
    case Dark = 'dark';
    case Light = 'light';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Dark => __('Dark'),
            self::Light => __('Light'),
            self::System => __('System'),
        };
    }

    /** Heroicon name used by Flux. */
    public function icon(): string
    {
        return match ($this) {
            self::Dark => 'moon',
            self::Light => 'sun',
            self::System => 'computer-desktop',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn (self $theme) => $theme->label(), self::cases()),
        );
    }
}
