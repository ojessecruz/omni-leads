<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Qualified = 'qualified';
    case Discarded = 'discarded';
    case Contacted = 'contacted';
    case Replied = 'replied';

    public function label(): string
    {
        return match ($this) {
            self::New => __('New'),
            self::Qualified => __('Qualified'),
            self::Discarded => __('Discarded'),
            self::Contacted => __('Contacted'),
            self::Replied => __('Replied'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'zinc',
            self::Qualified => 'lime',
            self::Discarded => 'red',
            self::Contacted => 'blue',
            self::Replied => 'green',
        };
    }
}
