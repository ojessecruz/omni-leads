<?php

namespace App\Enums;

enum OutboundEventType: string
{
    case Sent = 'sent';
    case Replied = 'replied';
    case Bounced = 'bounced';
    case Unsubscribed = 'unsubscribed';

    public function label(): string
    {
        return match ($this) {
            self::Sent => __('Email sent'),
            self::Replied => __('Replied'),
            self::Bounced => __('Bounced'),
            self::Unsubscribed => __('Unsubscribed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sent => 'blue',
            self::Replied => 'green',
            self::Bounced => 'red',
            self::Unsubscribed => 'amber',
        };
    }
}
