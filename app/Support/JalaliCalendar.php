<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Jalali (Solar Hijri) to Gregorian conversion.
 *
 * The Connectix panel timestamps its wallet transactions in the Iranian
 * calendar, and legacy `setup/setup.php` converted them while importing, so the
 * wallet ledger read correctly in the admin panel. The rewrite keeps the same
 * conversion; without it every imported transaction would land about thirty
 * years in the past.
 *
 * The algorithm is the one legacy used (the same arithmetic conversion found in
 * jalaali-js and the old PHP date pickers), ported rather than replaced: it is
 * the version whose output the existing rows were written with, so the ledger
 * stays consistent. The rewrite deliberately takes no new dependency for it.
 *
 * @see gregorian_jalali.php in the legacy root
 */
class JalaliCalendar
{
    private const GREGORIAN_DAYS = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    private const JALALI_DAYS = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

    /**
     * Convert a Jalali date to a Gregorian one.
     *
     * @return array{year: int, month: int, day: int}
     */
    public static function toGregorian(int $year, int $month, int $day): array
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Jalali month out of range: '.$month);
        }

        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException('Jalali day out of range: '.$day);
        }

        $jy = $year - 979;
        $jm = $month - 1;
        $jd = $day - 1;

        // Days since the Jalali epoch, 622-03-22.
        $jDayNo = 365 * $jy + intdiv($jy, 33) * 8 + intdiv($jy % 33 + 3, 4);

        for ($i = 0; $i < $jm; $i++) {
            $jDayNo += self::JALALI_DAYS[$i];
        }

        $jDayNo += $jd;

        $gDayNo = $jDayNo + 79;

        $gy = 1600 + 400 * intdiv($gDayNo, 146097);
        $gDayNo %= 146097;

        $leap = true;

        if ($gDayNo >= 36525) {
            $gDayNo--;
            $gy += 100 * intdiv($gDayNo, 36524);
            $gDayNo %= 36524;

            if ($gDayNo >= 365) {
                $gDayNo++;
            } else {
                $leap = false;
            }
        }

        $gy += 4 * intdiv($gDayNo, 1461);
        $gDayNo %= 1461;

        if ($gDayNo >= 366) {
            $leap = false;
            $gDayNo--;
            $gy += intdiv($gDayNo, 365);
            $gDayNo %= 365;
        }

        $i = 0;

        while ($gDayNo >= self::GREGORIAN_DAYS[$i] + ($i === 1 && $leap ? 1 : 0)) {
            $gDayNo -= self::GREGORIAN_DAYS[$i] + ($i === 1 && $leap ? 1 : 0);
            $i++;
        }

        return ['year' => $gy, 'month' => $i + 1, 'day' => $gDayNo + 1];
    }

    /**
     * Parse a timestamp as the panel sends it.
     *
     * Accepts "Y-m-d H:i:s", "Y/m/d H:i" and the date-only forms. A year below
     * 1500 is treated as Jalali, which is what the panel sends; anything from
     * 1500 onwards is assumed to be Gregorian already, so a panel that changes
     * calendar does not shift every record into the past.
     *
     * @return string|null A `Y-m-d H:i:s` string, or null when unparseable.
     */
    public static function parseTimestamp(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }

        $value = str_replace('/', '-', $value);

        if (! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?/', $value, $m)) {
            return null;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        $time = sprintf('%02d:%02d:%02d', (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));

        if ($year < 1500) {
            $gregorian = self::toGregorian($year, $month, $day);
            $year = $gregorian['year'];
            $month = $gregorian['month'];
            $day = $gregorian['day'];
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %s', $year, $month, $day, $time);
    }

    /**
     * Remove the thousands separators and units the panel puts in amounts.
     */
    public static function amount(mixed $value): int
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return 0;
        }

        $clean = str_replace([',', ' ', 'تومان', 'ريال'], '', (string) $value);

        return is_numeric($clean) ? (int) $clean : 0;
    }
}
