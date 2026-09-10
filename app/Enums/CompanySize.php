<?php

namespace App\Enums;

enum CompanySize: string
{
    case Micro = '1-10';
    case Small = '11-50';
    case Medium = '51-200';
    case Large = '200+';

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn (self $c) => __(':size employees', ['size' => $c->value]), self::cases()),
        );
    }
}
