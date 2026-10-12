<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Jalali (Solar Hijri) date helpers for the panel chrome.
 *
 * The layout header shows the day in the Iranian calendar next to a live
 * Tehran clock, and greets the admin by the time of day. {@see JalaliCalendar}
 * already converts Jalali → Gregorian for the wallet ledger; this class is the
 * missing direction — Gregorian → Jalali — plus the small presentation helpers
 * the chrome needs. No dependency is taken for it, for the same reason the
 * ledger conversion has none: the panel keeps its arithmetic in-house so an
 * offline install still renders.
 *
 * The conversion is the standard jalaali algorithm (the arithmetic one shared
 * by jalaali-js and the classic PHP date pickers), the inverse of the tables
 * {@see JalaliCalendar} uses, so a date survives the round trip unchanged.
 */
class JalaliDate
{
    /**
     * Days elapsed before each Gregorian month in a non-leap year.
     *
     * @var list<int>
     */
    private const GREGORIAN_CUMULATIVE_DAYS = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

    /**
     * Persian digits, for dates shown to the admin.
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
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $jy = $gy <= 1600 ? 0 : 979;
        $gy -= $gy <= 1600 ? 621 : 1600;

        // From March onward the leap-day falls inside this year, so the
        // day count must treat the year as having slipped one ahead.
        $gy2 = $gm > 2 ? $gy + 1 : $gy;

        $days = 365 * $gy
            + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400)
            - 80
            + $gd
            + self::GREGORIAN_CUMULATIVE_DAYS[$gm - 1];

        $jy += 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
        $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);

        return ['year' => $jy, 'month' => $jm, 'day' => $jd];
    }

    /**
     * Today's Jalali date.
     *
     * The clock the panel shows is Tehran's, independent of the application
     * timezone the install happens to run in.
     *
     * @return array{year: int, month: int, day: int}
     */
    public static function today(string $timeZone = 'Asia/Tehran'): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone($timeZone));

        return self::gregorianToJalali(
            (int) $now->format('Y'),
            (int) $now->format('n'),
            (int) $now->format('j'),
        );
    }

    /**
     * Latin digits swapped for Persian ones.
     */
    public static function toPersianDigits(string|int $value): string
    {
        return strtr((string) $value, self::PERSIAN_DIGITS);
    }

    /**
     * The greeting the topbar opens with: ۵ تا ۱۸ روز است، بقیه شب.
     */
    public static function greeting(int $hour): string
    {
        return $hour >= 5 && $hour < 18 ? 'روز بخیر' : 'شب بخیر';
    }
}
