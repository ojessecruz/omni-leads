<?php

namespace App\Enums;

enum SearchJobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Queued'),
            self::Running => __('Running'),
            self::Done => __('Done'),
            self::Failed => __('Failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::Running => 'blue',
            self::Done => 'green',
            self::Failed => 'red',
        };
    }
}
