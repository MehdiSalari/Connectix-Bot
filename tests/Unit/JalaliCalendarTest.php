<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\JalaliCalendar;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Jalali conversion the wallet import depends on.
 *
 * The panel timestamps its transactions in the Iranian calendar, and legacy
 * converted them while importing. Getting one day wrong here silently shifts
 * every imported ledger row, so the known anchors are pinned rather than
 * recomputed.
 */
class JalaliCalendarTest extends TestCase
{
    #[Test]
    #[DataProvider('conversions')]
    public function it_converts_the_jalali_anchors(int $jy, int $jm, int $jd, int $gy, int $gm, int $gd): void
    {
        $this->assertSame(
            ['year' => $gy, 'month' => $gm, 'day' => $gd],
            JalaliCalendar::toGregorian($jy, $jm, $jd),
        );
    }

    /**
     * @return array<string, array{int, int, int, int, int, int}>
     */
    public static function conversions(): array
    {
        return [
            'nowruz 1380' => [1380, 1, 1, 2001, 3, 21],
            'nowruz 1400' => [1400, 1, 1, 2021, 3, 21],
            'nowruz 1403' => [1403, 1, 1, 2024, 3, 20],
            'last day of 1403' => [1403, 12, 30, 2025, 3, 20],
            'mid 1404' => [1404, 3, 15, 2025, 6, 5],
            'leap day 1403' => [1403, 12, 30, 2025, 3, 20],
        ];
    }

    #[Test]
    public function it_parses_a_jalali_timestamp_and_keeps_the_time(): void
    {
        $this->assertSame('2024-08-02 14:30:00', JalaliCalendar::parseTimestamp('1403-05-12 14:30:00'));
        $this->assertSame('2024-08-02 00:00:00', JalaliCalendar::parseTimestamp('1403-05-12'));
    }

    #[Test]
    public function it_leaves_a_gregorian_timestamp_alone(): void
    {
        $this->assertSame('2024-08-02 09:05:03', JalaliCalendar::parseTimestamp('2024-08-02 09:05:03'));
    }

    #[Test]
    public function it_accepts_the_slash_and_short_month_forms_the_panel_uses(): void
    {
        $this->assertSame('2024-08-02 14:30:00', JalaliCalendar::parseTimestamp('1403/5/12 14:30'));
    }

    #[Test]
    public function it_rejects_the_values_the_panel_sends_for_missing_dates(): void
    {
        $this->assertNull(JalaliCalendar::parseTimestamp(null));
        $this->assertNull(JalaliCalendar::parseTimestamp(''));
        $this->assertNull(JalaliCalendar::parseTimestamp('null'));
        $this->assertNull(JalaliCalendar::parseTimestamp('not a date'));
    }

    #[Test]
    public function it_strips_the_thousands_separators_from_amounts(): void
    {
        $this->assertSame(1250000, JalaliCalendar::amount('1,250,000'));
        $this->assertSame(20000, JalaliCalendar::amount('20,000 تومان'));
        $this->assertSame(0, JalaliCalendar::amount(null));
        $this->assertSame(0, JalaliCalendar::amount('abc'));
    }

    #[Test]
    public function it_rejects_an_impossible_jalali_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JalaliCalendar::toGregorian(1403, 13, 1);
    }
}
