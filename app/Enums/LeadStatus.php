<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Qualified = 'qualified';
    case Discarded = 'discarded';
    case Contacted = 'contacted';

    public function label(): string
    {
        return match ($this) {
            self::New => __('New'),
            self::Qualified => __('Qualified'),
            self::Discarded => __('Discarded'),
            self::Contacted => __('Contacted'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'zinc',
            self::Qualified => 'lime',
            self::Discarded => 'red',
            self::Contacted => 'blue',
        };
    }
}
