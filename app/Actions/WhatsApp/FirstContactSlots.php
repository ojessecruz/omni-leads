<?php

namespace App\Actions\WhatsApp;

use App\Domains\WhatsApp\FirstContactSchedule;
use App\Enums\IntegrationProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Hands out the user's first-contact slots (FirstContactSchedule) and remembers the taken ones.
 * Booking runs under a lock, so two sends queued at once never share a slot.
 */
class FirstContactSlots
{
    /** Slots older than this no longer weigh on any cap. */
    private const KEEP_DAYS = 8;

    public function schedule(): FirstContactSchedule
    {
        $hours = config('whatsapp.business_hours');

        return new FirstContactSchedule(
            perHour: (int) config('whatsapp.first_contact.per_hour'),
            perDay: config('whatsapp.first_contact.per_day'),
            timezone: $hours['timezone'],
            hours: $hours['enabled'] ? ['days' => $hours['days'], 'from' => $hours['from'], 'until' => $hours['until']] : null,
            maxDaysAhead: (int) config('whatsapp.first_contact.max_days_ahead'),
        );
    }

    /** Books the next slot after a random gap; null when the queue is full. */
    public function book(User $user): ?CarbonImmutable
    {
        return $this->locked($user, function (array $taken) use ($user) {
            [$min, $max] = config('whatsapp.first_contact.gap');
            $now = now()->getTimestamp();
            $earliest = $taken === [] ? $now : max($taken) + random_int($min, $max);

            return $this->schedule()->slot($now, $earliest, $taken, $this->pairedAt($user));
        });
    }

    /** Books a slot right now (a message typed in the inbox) or returns null when the caps say no. */
    public function bookNow(User $user): ?CarbonImmutable
    {
        return $this->locked($user, function (array $taken) use ($user) {
            $now = now()->getTimestamp();

            return $this->schedule()->allowsNow($now, $taken, $this->pairedAt($user)) ? $now : null;
        });
    }

    /** First contacts the user may still send today, for the settings panel. */
    public function dailyCap(User $user): int
    {
        return $this->schedule()->dailyCap(now()->getTimestamp(), $this->pairedAt($user));
    }

    /**
     * @param  callable(list<int>): ?int  $pick
     */
    private function locked(User $user, callable $pick): ?CarbonImmutable
    {
        $key = 'whatsapp:first-contacts:'.$user->id;

        return Cache::lock($key.':lock', 10)->block(5, function () use ($key, $pick) {
            $cutoff = now()->subDays(self::KEEP_DAYS)->getTimestamp();
            $taken = array_values(array_filter((array) Cache::get($key, []), fn (int $t) => $t > $cutoff));

            $slot = $pick($taken);

            if ($slot === null) {
                return null;
            }

            Cache::put($key, [...$taken, $slot], now()->addDays(self::KEEP_DAYS));

            return CarbonImmutable::createFromTimestamp($slot);
        });
    }

    /** The warm-up counts from the last pairing; a number paired again starts over. */
    private function pairedAt(User $user): int
    {
        $connectedAt = $user->integration(IntegrationProvider::Evolution)?->credentials['connected_at'] ?? null;

        return $connectedAt ? CarbonImmutable::parse($connectedAt)->getTimestamp() : now()->getTimestamp();
    }
}
