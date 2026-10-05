<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class FollowupCalendar
{
    public static function working(CarbonImmutable $date, array $p): bool
    {
        return in_array($date->isoWeekday(), $p['weekdays'], true) && ! in_array($date->toDateString(), $p['holidays'], true);
    }

    public static function window(CarbonImmutable $utc, array $p): bool
    {
        $d = $utc->setTimezone($p['timezone']);
        $time = $d->format('H:i');

        return self::working($d, $p) && $time >= $p['opens'] && $time < $p['closes'];
    }

    public static function allowed(CarbonImmutable $utc, array $p): CarbonImmutable
    {
        $d = $utc->setTimezone($p['timezone']);
        for ($i = 0; $i < 740; $i++) {
            if (self::working($d, $p)) {
                if ($d->format('H:i') < $p['opens']) {
                    return $d->setTimeFromTimeString($p['opens'])->utc();
                }
                if ($d->format('H:i') < $p['closes']) {
                    return $d->utc();
                }
            }
            $d = $d->addDay()->startOfDay();
        }
        throw new \InvalidArgumentException('No working date within the bounded calendar horizon.');
    }

    public static function next(CarbonImmutable $sent, int $days, array $p): CarbonImmutable
    {
        $d = $sent->setTimezone($p['timezone']);
        $count = 0;
        for ($i = 0; $count < $days && $i < 740; $i++) {
            $d = $d->addDay();
            if (self::working($d, $p)) {
                $count++;
            }
        }
        if ($count < $days) {
            throw new \InvalidArgumentException('Business-day interval exceeds the calendar horizon.');
        }

        return self::allowed($d->utc(), $p);
    }
}
