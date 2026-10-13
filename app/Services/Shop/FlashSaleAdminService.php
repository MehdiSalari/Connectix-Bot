<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\FlashSale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin-facing service for the timed flash-sale (حراج زمان‌دار) feature.
 *
 * Mirrors the fork's FlashSaleService:
 *   - priceWithSale(planPrice, sale) -> [final, strike] percent|fixed floor at 0
 *   - remainingText(endsAt) Persian «X ساعت و Y دقیقه باقی‌مانده»
 *   - activeMap(planIds) for the shop/bot layer to consume later
 *   - validation starts<ends, discount 1..99 percent or >=1 fixed
 */
class FlashSaleAdminService
{
    /** Current Tehran time as 'Y-m-d H:i:s' (sortable string). */
    public static function nowTehran(): string
    {
        return now('Asia/Tehran')->format('Y-m-d H:i:s');
    }

    /** All sales, active ones first (by id DESC for active, then id DESC for inactive). */
    public static function all(): array
    {
        return FlashSale::query()
            ->orderByRaw("CASE WHEN is_active = 1 AND starts_at <= ? AND ends_at > ? THEN 0 ELSE 1 END", [self::nowTehran(), self::nowTehran()])
            ->orderBy('id', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Create a new flash sale.
     *
     * @param array{plan_id:string, plan_title:string, discount_type:'percent'|'fixed', discount_value:int, starts_at:string, ends_at:string} $data
     */
    public static function create(array $data): FlashSale
    {
        $now = self::nowTehran();

        if ($data['ends_at'] <= $data['starts_at']) {
            throw new \InvalidArgumentException('زمان پایان باید بعد از زمان شروع باشد.');
        }

        if ($data['discount_type'] === 'percent') {
            if ($data['discount_value'] < 1 || $data['discount_value'] > 99) {
                throw new \InvalidArgumentException('درصد تخفیف باید بین ۱ تا ۹۹ باشد.');
            }
        } else {
            if ($data['discount_value'] < 1) {
                throw new \InvalidArgumentException('مبلغ تخفیف باید حداقل ۱ تومان باشد.');
            }
        }

        return FlashSale::create([
            'plan_id'        => $data['plan_id'],
            'plan_title'     => $data['plan_title'],
            'discount_type'  => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'starts_at'      => $data['starts_at'],
            'ends_at'        => $data['ends_at'],
            'is_active'      => true,
        ]);
    }

    /** Toggle active state. */
    public static function setActive(int $id, bool $active): bool
    {
        $sale = FlashSale::find($id);
        if (!$sale) {
            return false;
        }

        $sale->is_active = $active;
        return $sale->save();
    }

    /** Delete a sale. */
    public static function delete(int $id): bool
    {
        $sale = FlashSale::find($id);
        if (!$sale) {
            return false;
        }

        return $sale->delete();
    }

    /**
     * Price after applying the sale.
     * Returns [finalPrice, strikePrice].
     */
    public static function priceWithSale(int $planPrice, ?array $sale): array
    {
        if (!$sale) {
            return [$planPrice, 0];
        }

        $value = (int) $sale['discount_value'];
        $final = ($sale['discount_type'] === 'percent')
            ? $planPrice - ($planPrice * $value / 100)
            : $planPrice - $value;

        $final = max(0, (int) round($final));

        return [$final, $planPrice];
    }

    /** Persian remaining time text: «X ساعت و Y دقیقه باقی‌مانده». */
    public static function remainingText(string $endsAt): string
    {
        try {
            $end = Carbon::parse($endsAt, 'Asia/Tehran');
            $now = now('Asia/Tehran');
            $diffInSeconds = $now->diffInSeconds($end);

            if ($diffInSeconds <= 0) {
                return 'به پایان رسیده';
            }

            $hours = (int) ($diffInSeconds / 3600);
            $minutes = (int) (($diffInSeconds % 3600) / 60);

            $parts = [];
            if ($hours > 0) {
                $parts[] = "{$hours} ساعت";
            }
            $parts[] = "{$minutes} دقیقه";

            return implode(' و ', $parts) . ' باقی‌مانده';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Active sales keyed by plan_id (newest sale wins per plan).
     * For the shop/bot layer to consume.
     *
     * @param  array<int,string>|null $planIds If null, return all active.
     * @return array<string,array>
     */
    public static function activeMap(?array $planIds = null): array
    {
        $now = self::nowTehran();

        $query = FlashSale::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->orderBy('id', 'asc'); // later rows overwrite earlier

        if ($planIds !== null && $planIds !== []) {
            $query->whereIn('plan_id', $planIds);
        }

        $map = [];
        foreach ($query->get() as $sale) {
            $map[$sale->plan_id] = $sale->toArray();
        }

        return $map;
    }

    /** Convert <input type="datetime-local"> value to 'Y-m-d H:i:s', or null if invalid. */
    public static function parseInputTime(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $v = str_replace('T', ' ', $v);
        $ts = strtotime($v);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    /**
     * Gregorian 'Y-m-d' (or 'Y-m-d H:i:s') -> Jalali 'Y/m/d'.
     *
     * The panel timestamps in the Gregorian calendar and reads in the Jalali
     * one; this is the display-side conversion the wallet ledger does, reusing
     * the same PersianCalendar algorithm the dashboard uses. Shared by the
     * wave-3 feature pages (flash sales, loyalty, notifications) because they
     * are the only views that date-stamp rows.
     */
    public static function jalaliDate(?string $value, string $sep = '/'): string
    {
        $value = trim((string) $value);
        if ($value === '' || ! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m)) {
            return $value;
        }

        try {
            $j = \App\Support\PersianCalendar::toJalali((int) $m[1], (int) $m[2], (int) $m[3]);
        } catch (\Throwable $e) {
            return $value;
        }

        return sprintf('%04d%s%02d%s%02d', $j['year'], $sep, $j['month'], $sep, $j['day']);
    }

    /** Ready-to-send announcement text for the broadcast page. */
    public static function announcementText(array $sale): string
    {
        $title = $sale['plan_title'] ?: ('پلن ' . $sale['plan_id']);
        $off = ($sale['discount_type'] === 'percent')
            ? ((int) $sale['discount_value'] . '٪')
            : (number_format((int) $sale['discount_value']) . ' تومان');

        // Gregorian end time shown in the panel's Jalali calendar.
        $endJ = self::jalaliDate($sale['ends_at'], '/');

        return "🔥 <b>حراج ویژه!</b>\n\n📦 {$title}\n💥 {$off} تخفیف\n⏳ فقط تا {$endJ}\n\nبرای خرید از منوی ربات، «خرید سرویس» را بزنید 👇";
    }
}