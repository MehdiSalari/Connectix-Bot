<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Gregorian → Jalali conversion plus the Jalali month windows the dashboard
 * charts are built on — everything in Asia/Tehran.
 *
 * {@see JalaliCalendar} walks the other direction for wallet rows imported
 * from the Connectix panel; this is the missing direction. The conversion is
 * the standard jalaali algorithm, ported from jalaali-js (jalCal / g2d / d2j /
 * j2d), so its output matches the Jalali dates the panel chrome already
 * prints. No dependency is taken for it, for the same reason the ledger
 * conversion took none: the panel keeps its calendar arithmetic in-house so
 * an offline install still renders.
 */
class PersianCalendar
{
    private const TZ = 'Asia/Tehran';

    /**
     * Leap-year breakpoints of the Jalali calendar, from jalaali-js.
     *
     * @var list<int>
     */
    private const BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

    /**
     * Persian digits, for chart labels shown to the admin.
     *
     * @var array<string, string>
     */
    private const PERSIAN_DIGITS = [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ];

    /**
     * Convert a Gregorian date to a Jalali one.
     *
     * @return array{year: int, month: int, day: int}
     */
    public static function toJalali(int $year, int $month, int $day): array
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Gregorian month out of range: '.$month);
        }

        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException('Gregorian day out of range: '.$day);
        }

        return self::dayToJalali(self::gregorianToDay($year, $month, $day));
    }

    /**
     * Midnight (Tehran) of the first day of the Jalali month the moment
     * falls in. The month comparison on the dashboard hangs off this: a
     * half-finished Jalali month must be measured against the same slice of
     * the previous one, so the boundary has to be the calendar's, not the
     * Gregorian month's.
     */
    public static function jalaliMonthStart(Carbon $moment): Carbon
    {
        $local = $moment->copy()->timezone(self::TZ);
        $j = self::toJalali((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));

        return self::jalaliToCarbon($j['year'], $j['month'], 1);
    }

    /**
     * The last `$months` Jalali months including the current one, oldest
     * first. `end` is exclusive — it is the next month's start — so every
     * window feeds a `>= start` / `< end` query pair directly.
     *
     * @return list<array{start: Carbon, end: Carbon}>
     */
    public static function jalaliMonthsWindow(Carbon $moment, int $months = 6): array
    {
        if ($months < 1) {
            throw new InvalidArgumentException('The month window must span at least one month.');
        }

        $local = $moment->copy()->timezone(self::TZ);
        $j = self::toJalali((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));

        $bounds = [];

        for ($back = $months - 1; $back >= 0; $back--) {
            [$year, $month] = self::shift($j['year'], $j['month'], -$back);
            $bounds[] = self::jalaliToCarbon($year, $month, 1);
        }

        // The exclusive end of the newest month in the window.
        [$year, $month] = self::shift($j['year'], $j['month'], 1);
        $bounds[] = self::jalaliToCarbon($year, $month, 1);

        $window = [];

        for ($i = 0; $i < $months; $i++) {
            $window[] = ['start' => $bounds[$i], 'end' => $bounds[$i + 1]];
        }

        return $window;
    }

    /**
     * Latin digits swapped for Persian ones.
     */
    public static function toPersianDigits(string|int $value): string
    {
        return strtr((string) $value, self::PERSIAN_DIGITS);
    }

    /**
     * Move a Jalali (year, month) pair by whole months, wrapping over the
     * year boundary.
     *
     * @return array{0: int, 1: int}
     */
    private static function shift(int $year, int $month, int $by): array
    {
        $month += $by;

        while ($month < 1) {
            $month += 12;
            $year--;
        }

        while ($month > 12) {
            $month -= 12;
            $year++;
        }

        return [$year, $month];
    }

    /**
     * Midnight (Tehran) of a Jalali date.
     *
     * @param  list<int>  $gregorian
     */
    private static function jalaliToCarbon(int $year, int $month, int $day): Carbon
    {
        $g = self::jalaliToGregorian($year, $month, $day);

        return Carbon::create($g[0], $g[1], $g[2], 0, 0, 0, self::TZ);
    }

    // ------------------------------------------------------------------
    // The jalaali-js core, ported verbatim. `div` truncates toward zero
    // exactly like JavaScript's number coercion, which the algorithm's
    // leap arithmetic depends on; PHP's intdiv would floor instead.
    // ------------------------------------------------------------------

    private static function div(int $a, int $b): int
    {
        return (int) ($a / $b);
    }

    private static function mod(int $a, int $b): int
    {
        return $a - $b * self::div($a, $b);
    }

    /**
     * Leap information and the March equinox day for a Jalali year.
     *
     * @return array{leap: int, gy: int, march: int}
     */
    private static function jalCal(int $jy): array
    {
        $breaks = self::BREAKS;
        $count = count($breaks);
        $gy = $jy + 621;
        $leapJ = -14;
        $jp = $breaks[0];
        $jump = 0;

        for ($i = 1; $i < $count; $i++) {
            $jm = $breaks[$i];
            $jump = $jm - $jp;

            if ($jy < $jm) {
                break;
            }

            $leapJ += self::div($jump, 33) * 8 + self::div(self::mod($jump, 33), 4);
            $jp = $jm;
        }

        $n = $jy - $jp;
        $leapJ += self::div($n, 33) * 8 + self::div(self::mod($n, 33) + 3, 4);

        if (self::mod($jump, 33) === 4 && $jump - $n === 4) {
            $leapJ++;
        }

        $leapG = self::div($gy, 4) - self::div((self::div($gy, 100) + 1) * 3, 4) - 150;
        $march = 20 + $leapJ - $leapG;

        if ($jump - $n < 6) {
            $n = $n - $jump + self::div($jump + 4, 33) * 33;
        }

        $leap = self::mod(self::mod($n + 1, 33) - 1, 4);

        if ($leap === -1) {
            $leap = 4;
        }

        return ['leap' => $leap, 'gy' => $gy, 'march' => $march];
    }

    /**
     * Gregorian date → Julian Day Number (jalaali-js g2d).
     */
    private static function gregorianToDay(int $gy, int $gm, int $gd): int
    {
        $d = self::div(($gy + self::div($gm - 8, 6) + 100100) * 1461, 4)
            + self::div(153 * self::mod($gm + 9, 12) + 2, 5)
            + $gd - 34840408;

        return $d - self::div(self::div($gy + 100100 + self::div($gm - 8, 6), 100) * 3, 4) + 752;
    }

    /**
     * Julian Day Number → Gregorian date (jalaali-js d2g).
     *
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     */
    private static function dayToGregorian(int $jdn): array
    {
        $j = 4 * $jdn + 139361631;
        $j = $j + self::div(self::div(4 * $jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
        $i = self::div(self::mod($j, 1461), 4) * 5 + 308;
        $gd = self::div(self::mod($i, 153), 5) + 1;
        $gm = self::mod(self::div($i, 153), 12) + 1;
        $gy = self::div($j, 1461) - 100100 + self::div(8 - $gm, 6);

        return [$gy, $gm, $gd];
    }

    /**
     * Julian Day Number → Jalali date (jalaali-js d2j).
     *
     * @return array{year: int, month: int, day: int}
     */
    private static function dayToJalali(int $jdn): array
    {
        $gy = self::dayToGregorian($jdn)[0];
        $jy = $gy - 621;
        $r = self::jalCal($jy);
        $jdn1f = self::gregorianToDay($gy, 3, $r['march']);
        $k = $jdn - $jdn1f;

        if ($k >= 0) {
            if ($k <= 185) {
                return ['year' => $jy, 'month' => self::div($k, 31) + 1, 'day' => self::mod($k, 31) + 1];
            }

            $k -= 186;
        } else {
            $jy--;
            $k += 179;

            if ($r['leap'] === 1) {
                $k++;
            }
        }

        return ['year' => $jy, 'month' => self::div($k, 30) + 7, 'day' => self::mod($k, 30) + 1];
    }

    /**
     * Jalali date → Gregorian date (jalaali-js j2g).
     *
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     */
    private static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $r = self::jalCal($jy);

        return self::dayToGregorian(
            self::gregorianToDay($r['gy'], 3, $r['march'])
                + ($jm - 1) * 31
                - self::div($jm, 7) * ($jm - 7)
                + $jd - 1
        );
    }
}
