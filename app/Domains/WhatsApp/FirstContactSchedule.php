<?php

namespace App\Domains\WhatsApp;

use DateTimeImmutable;
use DateTimeZone;

/**
 * When the next first contact (a message to a number that never replied) may go out.
 * Sending many of them in a burst is what gets a number restricted, so each one takes
 * a slot that keeps an hourly cap, a daily cap that grows while a new number warms up,
 * and business hours. Works on unix timestamps; the caller keeps the taken slots.
 */
class FirstContactSchedule
{
    private DateTimeZone $timezone;

    /**
     * @param  array<int, int>  $perDay  days since the phone was paired => daily cap
     * @param  array{days: list<int>, from: int, until: int}|null  $hours  ISO weekdays and hours; null = any time
     */
    public function __construct(
        private int $perHour,
        private array $perDay,
        string $timezone,
        private ?array $hours,
        private int $maxDaysAhead,
    ) {
        $this->timezone = new DateTimeZone($timezone);
        ksort($this->perDay);
    }

    /**
     * Earliest moment from $earliest on that keeps every cap, or null when the queue
     * would run past the scheduling horizon.
     *
     * @param  list<int>  $taken  first contacts already sent or scheduled
     */
    public function slot(int $now, int $earliest, array $taken, int $pairedAt): ?int
    {
        $at = max($now, $earliest);
        $horizon = $now + $this->maxDaysAhead * 86400;

        while ($at <= $horizon) {
            $open = $this->nextOpening($at);

            if ($open !== $at) {
                $at = $open;

                continue;
            }

            [$dayStart, $dayEnd] = $this->day($at);

            if ($this->countBetween($taken, $dayStart, $dayEnd) >= $this->dailyCap($at, $pairedAt)) {
                $at = $dayEnd;

                continue;
            }

            // Rolling hour: the slots in (at - 1h, at].
            $lastHour = array_values(array_filter($taken, fn (int $t) => $t > $at - 3600 && $t <= $at));
            sort($lastHour);

            if (count($lastHour) >= $this->perHour) {
                $at = $lastHour[count($lastHour) - $this->perHour] + 3600;

                continue;
            }

            return $at;
        }

        return null;
    }

    /**
     * Whether a first contact may go out right now (typed in the inbox, so no gap).
     *
     * @param  list<int>  $taken
     */
    public function allowsNow(int $now, array $taken, int $pairedAt): bool
    {
        return $this->slot($now, $now, $taken, $pairedAt) === $now;
    }

    public function dailyCap(int $at, int $pairedAt): int
    {
        $days = intdiv(max(0, $at - $pairedAt), 86400);
        $cap = 0;

        foreach ($this->perDay as $from => $limit) {
            if ($days >= $from) {
                $cap = $limit;
            }
        }

        return $cap;
    }

    public function perHour(): int
    {
        return $this->perHour;
    }

    /** $at itself when inside business hours, otherwise the start of the next opening. */
    private function nextOpening(int $at): int
    {
        if ($this->hours === null || $this->hours['days'] === []) {
            return $at;
        }

        $local = $this->local($at);

        for ($i = 0; $i < 8; $i++) {
            if (in_array((int) $local->format('N'), $this->hours['days'], true)) {
                $open = $local->setTime($this->hours['from'], 0);
                $close = $local->setTime($this->hours['until'], 0);

                if ($local < $open) {
                    return $open->getTimestamp();
                }

                if ($local < $close) {
                    return $local->getTimestamp();
                }
            }

            $local = $local->modify('+1 day')->setTime(0, 0);
        }

        return $at;
    }

    /** @return array{int, int} start and end of $at's day, in the schedule's timezone */
    private function day(int $at): array
    {
        $start = $this->local($at)->setTime(0, 0);

        return [$start->getTimestamp(), $start->modify('+1 day')->getTimestamp()];
    }

    /** @param  list<int>  $taken */
    private function countBetween(array $taken, int $from, int $until): int
    {
        return count(array_filter($taken, fn (int $t) => $t >= $from && $t < $until));
    }

    private function local(int $at): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$at))->setTimezone($this->timezone);
    }
}
