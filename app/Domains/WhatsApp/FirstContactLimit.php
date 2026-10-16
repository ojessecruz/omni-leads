<?php

namespace App\Domains\WhatsApp;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Evolution is not the official WhatsApp API: messaging many people who never wrote back
 * gets the number banned. Replying to someone who wrote is free; messages to numbers that
 * never replied are capped per user per hour.
 */
class FirstContactLimit
{
    public const PER_HOUR = 20;

    /** Counts one message and returns false once the hourly cap is reached. */
    public function attempt(User $user): bool
    {
        return RateLimiter::attempt($this->key($user), self::PER_HOUR, fn () => true, 3600);
    }

    public function availableInMinutes(User $user): int
    {
        return (int) ceil(RateLimiter::availableIn($this->key($user)) / 60);
    }

    private function key(User $user): string
    {
        return 'whatsapp:first-contact:'.$user->id;
    }
}
