<?php

declare(strict_types=1);

namespace App\Services\Coupons;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Local discount codes, independent of the Connectix seller panel.
 *
 * Port of the fork's LocalCouponService CRUD semantics, with one deliberate
 * difference: the fork kept them in a dedicated `local_coupons` table, and
 * this rewrite does not add that schema. The coupons live in `panel_settings`
 * under the `local_coupons` key as a JSON list, one object per coupon:
 *
 *     {code, kind percent|fixed, value, max_uses, used_count,
 *      starts_at, ends_at, is_active}
 *
 * The fork's fields map over as: per_cent→value (kind percent) or amount→value
 * (kind fixed), usage_limit→max_uses, start_at/end_at→starts_at/ends_at; the
 * per-plan restriction (plan_ids) and the admin note the fork carried are not
 * ported because this wave's panel has no plan catalogue to pick from yet.
 *
 * Codes are unique case-insensitively (the fork's UNIQUE column was case
 * sensitive, but a code typed in any case must be the same coupon), and the
 * usage count lives on the coupon itself instead of in the fork's
 * CouponService/coupon_restrictions side table.
 */
class LocalCouponService
{
    /** The panel_settings key holding the JSON list. */
    public const STORE_KEY = 'local_coupons';

    /**
     * Every local coupon, newest first (the fork's ORDER BY id DESC).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $coupons = $this->read();

        usort($coupons, fn (array $a, array $b): int => ($b['seq'] ?? 0) <=> ($a['seq'] ?? 0));

        return $coupons;
    }

    /**
     * One coupon by code, case-insensitive; the bot's redemption flow reads
     * this. `WELCOME20` and `welcome20` are the same coupon.
     */
    public function find(string $code): ?array
    {
        $needle = mb_strtolower(trim($code));

        foreach ($this->read() as $coupon) {
            if (mb_strtolower((string) $coupon['code']) === $needle) {
                return $coupon;
            }
        }

        return null;
    }

    /**
     * Replace the stored list.
     *
     * Validates shape per coupon: unique codes (case-insensitive), percent
     * within 1–100, a fixed amount above zero, and a sane window. Throws a
     * validation exception naming the first broken code.
     *
     * @param  list<array<string, mixed>>  $coupons
     */
    public function saveList(array $coupons): void
    {
        $seen = [];
        $clean = [];
        $seq = 0;

        foreach ($coupons as $coupon) {
            $code = trim((string) ($coupon['code'] ?? ''));

            if (! preg_match('/^[A-Za-z0-9_\-]{3,40}$/', $code)) {
                throw ValidationException::withMessages([
                    'code' => 'کد باید ۳ تا ۴۰ کاراکتر و فقط شامل حروف انگلیسی، عدد، _ و - باشد.',
                ]);
            }

            $key = mb_strtolower($code);

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'code' => "این کد قبلاً ساخته شده است: {$code}",
                ]);
            }

            $seen[$key] = true;

            $kind = ($coupon['kind'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
            $value = (int) preg_replace('/\D+/', '', (string) ($coupon['value'] ?? '0'));

            if ($kind === 'percent' && ($value < 1 || $value > 100)) {
                throw ValidationException::withMessages([
                    'value' => "درصد تخفیف باید بین ۱ تا ۱۰۰ باشد: {$code}",
                ]);
            }

            if ($kind === 'fixed' && $value < 1) {
                throw ValidationException::withMessages([
                    'value' => "مبلغ تخفیف نامعتبر است: {$code}",
                ]);
            }

            $startsAt = $this->normalizeDate($coupon['starts_at'] ?? null);
            $endsAt = $this->normalizeDate($coupon['ends_at'] ?? null);

            if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
                throw ValidationException::withMessages([
                    'ends_at' => "پایان باید بعد از شروع باشد: {$code}",
                ]);
            }

            $clean[] = [
                'code' => $code,
                'kind' => $kind,
                'value' => $value,
                'max_uses' => max(0, (int) ($coupon['max_uses'] ?? 0)),
                'used_count' => max(0, (int) ($coupon['used_count'] ?? 0)),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'is_active' => (bool) ($coupon['is_active'] ?? false),
                'seq' => ++$seq,
            ];
        }

        $this->write($clean);
    }

    /**
     * Add one coupon from the panel form.
     *
     * @param  array<string, mixed>  $fields
     */
    public function add(array $fields): void
    {
        $coupons = $this->read();

        $coupons[] = [
            'code' => $fields['code'] ?? '',
            'kind' => $fields['kind'] ?? 'percent',
            'value' => $fields['value'] ?? 0,
            'max_uses' => $fields['max_uses'] ?? 0,
            'used_count' => 0,
            'starts_at' => $fields['starts_at'] ?? null,
            'ends_at' => $fields['ends_at'] ?? null,
            'is_active' => (bool) ($fields['is_active'] ?? false),
        ];

        $this->saveList($coupons);
    }

    /**
     * Update one coupon in place, matched by its (case-insensitive) code.
     *
     * @param  array<string, mixed>  $fields
     */
    public function update(string $code, array $fields): void
    {
        $target = mb_strtolower(trim($code));
        $coupons = $this->read();
        $found = false;

        foreach ($coupons as $index => $coupon) {
            if (mb_strtolower((string) $coupon['code']) !== $target) {
                continue;
            }

            $found = true;
            $coupons[$index] = array_merge($coupon, array_intersect_key($fields, [
                'code' => true, 'kind' => true, 'value' => true, 'max_uses' => true,
                'starts_at' => true, 'ends_at' => true, 'is_active' => true,
            ]));
        }

        if (! $found) {
            throw ValidationException::withMessages(['code' => 'کد پیدا نشد.']);
        }

        $this->saveList($coupons);
    }

    /**
     * Remove one coupon by code, case-insensitive. Unknown codes are a no-op,
     * like the fork's delete of a missing id.
     */
    public function delete(string $code): void
    {
        $target = mb_strtolower(trim($code));

        $remaining = array_values(array_filter(
            $this->read(),
            fn (array $coupon): bool => mb_strtolower((string) $coupon['code']) !== $target,
        ));

        $this->write($remaining);
    }

    /**
     * Flip one coupon's active flag (the table's inline toggle).
     */
    public function setActive(string $code, bool $active): void
    {
        $this->update($code, ['is_active' => $active]);
    }

    /**
     * Count one redemption (the bot calls this when a coupon is applied).
     * Unknown codes are a no-op.
     */
    public function markUsed(string $code): void
    {
        $target = mb_strtolower(trim($code));
        $coupons = $this->read();
        $found = false;

        foreach ($coupons as $index => $coupon) {
            if (mb_strtolower((string) $coupon['code']) !== $target) {
                continue;
            }

            $coupons[$index]['used_count'] = (int) $coupon['used_count'] + 1;
            $found = true;

            break;
        }

        if ($found) {
            $this->write($coupons);
        }
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function read(): array
    {
        $raw = (string) DB::table('panel_settings')->where('setting_key', self::STORE_KEY)->value('setting_value');

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded as $coupon) {
            if (! is_array($coupon)) {
                continue;
            }

            $out[] = [
                'code' => (string) ($coupon['code'] ?? ''),
                'kind' => (($coupon['kind'] ?? 'percent') === 'fixed') ? 'fixed' : 'percent',
                'value' => (int) ($coupon['value'] ?? 0),
                'max_uses' => (int) ($coupon['max_uses'] ?? 0),
                'used_count' => (int) ($coupon['used_count'] ?? 0),
                'starts_at' => $coupon['starts_at'] ?? null,
                'ends_at' => $coupon['ends_at'] ?? null,
                'is_active' => (bool) ($coupon['is_active'] ?? false),
                'seq' => (int) ($coupon['seq'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $coupons
     */
    private function write(array $coupons): void
    {
        DB::table('panel_settings')->updateOrInsert(
            ['setting_key' => self::STORE_KEY],
            ['setting_value' => json_encode(array_values($coupons), JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
        );
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace('T', ' ', (string) $value));

        if ($value === '') {
            return null;
        }

        $stamp = strtotime($value);

        return $stamp === false ? null : date('Y-m-d H:i:s', $stamp);
    }
}
