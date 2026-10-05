<?php

namespace Tests\Unit;

use App\Support\FollowupCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FollowupCalendarTest extends TestCase
{
    private function policy(): array
    {
        return ['timezone' => 'Asia/Kuala_Lumpur', 'weekdays' => [1, 2, 3, 4, 5], 'holidays' => ['2026-10-12'], 'opens' => '09:00', 'closes' => '17:00'];
    }

    public static function dates(): array
    {
        return [
            'weekend' => ['2026-10-09T08:00:00Z', 2, '2026-10-14T08:00:00Z'],
            'early' => ['2026-10-05T00:00:00Z', 2, '2026-10-07T01:00:00Z'],
            'close-exclusive' => ['2026-10-05T09:00:00Z', 2, '2026-10-08T01:00:00Z'],
            'holidays' => ['2026-10-09T02:00:00Z', 1, '2026-10-13T02:00:00Z'],
        ];
    }

    #[DataProvider('dates')]
    public function test_business_days_windows_and_local_holidays(string $sent, int $days, string $expected): void
    {
        $this->assertSame(CarbonImmutable::parse($expected)->toIso8601String(), FollowupCalendar::next(CarbonImmutable::parse($sent), $days, $this->policy())->toIso8601String());
    }

    public function test_boundaries_and_weekend_roll_forward(): void
    {
        $p = $this->policy();
        $this->assertTrue(FollowupCalendar::window(CarbonImmutable::parse('2026-10-05T01:00:00Z'), $p));
        $this->assertFalse(FollowupCalendar::window(CarbonImmutable::parse('2026-10-05T09:00:00Z'), $p));
        $this->assertFalse(FollowupCalendar::window(CarbonImmutable::parse('2026-10-10T02:00:00Z'), $p));
        $this->assertSame('2026-10-13T01:00:00+00:00', FollowupCalendar::allowed(CarbonImmutable::parse('2026-10-10T02:00:00Z'), $p)->toIso8601String());
    }

    public function test_another_timezone_and_dst_keep_local_window(): void
    {
        $p = $this->policy();
        $p['timezone'] = 'America/New_York';
        $p['holidays'] = [];
        $this->assertSame('2026-11-02T14:00:00+00:00', FollowupCalendar::next(CarbonImmutable::parse('2026-10-30T12:00:00Z'), 1, $p)->toIso8601String());
    }
}
