<?php

namespace Tests\Unit;

use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BusinessCalendarTest extends TestCase
{
    private const WEEKDAYS_9_TO_5 = [
        1 => ['09:00', '17:00'], 2 => ['09:00', '17:00'], 3 => ['09:00', '17:00'],
        4 => ['09:00', '17:00'], 5 => ['09:00', '17:00'],
    ];

    private function london(): BusinessCalendar
    {
        return new BusinessCalendar('Europe/London', self::WEEKDAYS_9_TO_5);
    }

    private function utc(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'UTC');
    }

    public static function additions(): array
    {
        // 2026-01-05 is a Monday; London is on GMT (UTC+0) in January.
        return [
            'within the same day' => ['2026-01-05 10:00', 60, '2026-01-05 11:00'],
            'ending exactly at close' => ['2026-01-05 16:00', 60, '2026-01-05 17:00'],
            'spilling into the next day' => ['2026-01-05 16:30', 60, '2026-01-06 09:30'],
            'created before opening' => ['2026-01-05 06:00', 30, '2026-01-05 09:30'],
            'created after closing' => ['2026-01-05 20:00', 30, '2026-01-06 09:30'],
            'Friday afternoon to Monday' => ['2026-01-09 16:30', 60, '2026-01-12 09:30'],
            'created on Saturday' => ['2026-01-10 12:00', 15, '2026-01-12 09:15'],
            'three full business days' => ['2026-01-05 09:00', 3 * 8 * 60, '2026-01-07 17:00'],
            'zero minutes' => ['2026-01-10 12:00', 0, '2026-01-10 12:00'],
        ];
    }

    #[DataProvider('additions')]
    public function test_adding_business_minutes(string $from, int $minutes, string $expected): void
    {
        $this->assertEquals($this->utc($expected), $this->london()->addMinutes($this->utc($from), $minutes));
    }

    public function test_calculations_use_the_organization_timezone(): void
    {
        // New York is UTC-5 in January: 09:00-17:00 local is 14:00-22:00 UTC.
        $calendar = new BusinessCalendar('America/New_York', self::WEEKDAYS_9_TO_5);

        $due = $calendar->addMinutes($this->utc('2026-01-05 21:30'), 60);

        $this->assertEquals($this->utc('2026-01-06 14:30'), $due);
    }

    public function test_daylight_saving_change_is_handled(): void
    {
        // London clocks go forward on Sunday 2026-03-29 (GMT -> BST).
        $everyDay = array_fill_keys(range(1, 7), ['09:00', '17:00']);
        $calendar = new BusinessCalendar('Europe/London', $everyDay);

        // Saturday 16:00 GMT: 60 minutes left on Saturday, 60 more on Sunday from 09:00 BST (08:00 UTC).
        $due = $calendar->addMinutes($this->utc('2026-03-28 16:00'), 120);

        $this->assertEquals($this->utc('2026-03-29 09:00'), $due);
    }

    public function test_an_organization_without_hours_is_open_around_the_clock(): void
    {
        $calendar = new BusinessCalendar('UTC', []);

        $this->assertEquals($this->utc('2026-01-11 01:00'), $calendar->addMinutes($this->utc('2026-01-10 23:00'), 120));
        $this->assertSame(120, $calendar->minutesBetween($this->utc('2026-01-10 23:00'), $this->utc('2026-01-11 01:00')));
    }

    public function test_business_minutes_between_two_moments(): void
    {
        $calendar = $this->london();

        // Friday 16:00 to Monday 10:00 = 60 (Fri) + 60 (Mon).
        $this->assertSame(120, $calendar->minutesBetween($this->utc('2026-01-09 16:00'), $this->utc('2026-01-12 10:00')));
        // Entirely outside business hours.
        $this->assertSame(0, $calendar->minutesBetween($this->utc('2026-01-10 10:00'), $this->utc('2026-01-11 18:00')));
        // Reversed range.
        $this->assertSame(0, $calendar->minutesBetween($this->utc('2026-01-12 10:00'), $this->utc('2026-01-09 16:00')));
    }

    public function test_adding_then_measuring_round_trips(): void
    {
        $calendar = $this->london();
        $start = $this->utc('2026-01-08 15:17');

        foreach ([1, 59, 480, 1000, 5000] as $minutes) {
            $this->assertSame($minutes, $calendar->minutesBetween($start, $calendar->addMinutes($start, $minutes)));
        }
    }
}
