<?php

namespace App\Support;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use LogicException;

/**
 * Business-time arithmetic for one organization.
 *
 * Working hours are given per ISO weekday (1 = Monday) as local "H:i" times in
 * the organization's timezone; each day has at most one window and windows do
 * not cross midnight. With no working hours at all the calendar is 24/7.
 *
 * All calculations walk day by day in local time, so daylight-saving changes
 * are handled by Carbon; results are returned in UTC.
 */
final class BusinessCalendar
{
    /** Safety net against invalid configuration; ten years of days. */
    private const MAX_DAYS = 3660;

    /**
     * @param  array<int, array{0: string, 1: string}>  $hours  weekday => [opens, closes]
     */
    public function __construct(
        private readonly string $timezone,
        private readonly array $hours,
    ) {}

    public static function forOrganization(Organization $organization): self
    {
        $hours = $organization->businessHours
            ->mapWithKeys(fn ($day) => [$day->weekday => [substr($day->opens_at, 0, 5), substr($day->closes_at, 0, 5)]])
            ->all();

        return new self($organization->timezone, $hours);
    }

    public function isAlwaysOpen(): bool
    {
        return $this->hours === [];
    }

    /**
     * The moment that lies $minutes of business time after $from.
     */
    public function addMinutes(DateTimeInterface $from, int $minutes): CarbonImmutable
    {
        $from = CarbonImmutable::instance($from);

        if ($minutes <= 0) {
            return $from->utc();
        }

        if ($this->isAlwaysOpen()) {
            return $from->addMinutes($minutes)->utc();
        }

        $remaining = $minutes * 60;
        $cursor = $from->setTimezone($this->timezone);

        for ($day = 0; $day < self::MAX_DAYS; $day++) {
            $window = $this->windowFor($cursor);

            if ($window !== null && $cursor < $window[1]) {
                $start = $cursor->max($window[0]);
                $available = $window[1]->getTimestamp() - $start->getTimestamp();

                if ($remaining <= $available) {
                    return $start->addSeconds($remaining)->utc();
                }

                $remaining -= $available;
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        throw new LogicException('Business hours never open; check the configuration.');
    }

    /**
     * Business minutes elapsed between two moments (0 if $end is before $start).
     */
    public function minutesBetween(DateTimeInterface $start, DateTimeInterface $end): int
    {
        $start = CarbonImmutable::instance($start);
        $end = CarbonImmutable::instance($end);

        if ($end <= $start) {
            return 0;
        }

        if ($this->isAlwaysOpen()) {
            return intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
        }

        $seconds = 0;
        $cursor = $start->setTimezone($this->timezone);
        $end = $end->setTimezone($this->timezone);

        for ($day = 0; $day < self::MAX_DAYS && $cursor < $end; $day++) {
            $window = $this->windowFor($cursor);

            if ($window !== null) {
                $from = $cursor->max($window[0]);
                $to = $end->min($window[1]);
                $seconds += max(0, $to->getTimestamp() - $from->getTimestamp());
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return intdiv($seconds, 60);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null local open and close of that date
     */
    private function windowFor(CarbonImmutable $localDate): ?array
    {
        $hours = $this->hours[$localDate->dayOfWeekIso] ?? null;

        if ($hours === null) {
            return null;
        }

        [$opens, $closes] = $hours;

        return [
            $localDate->setTimeFromTimeString($opens),
            $localDate->setTimeFromTimeString($closes),
        ];
    }
}
