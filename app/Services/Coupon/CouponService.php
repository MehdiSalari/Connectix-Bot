<?php

declare(strict_types=1);

namespace App\Services\Coupon;

use App\Exceptions\ConnectixApiException;
use App\Services\Connectix\ConnectixService;
use Illuminate\Support\Facades\Log;

/**
 * Coupon lookup, validity checks and discount arithmetic.
 *
 * Port of `checkCoupon()`, of the validation ladder legacy performed inline in
 * bot.php (the `discount` action branch) and of the `apply` branch of
 * `discount()`.
 *
 * The rejection reasons are exposed as constants holding the exact Persian
 * strings legacy sent, so the wording a running reseller bot shows does not
 * change during the rewrite.
 */
class CouponService
{
    public const ERROR_INVALID = '🚫 کد تخفیف وارد شده صحیح نمی باشد!🚫';

    public const ERROR_INACTIVE = '🚫 کد تخفیف وارد شده غیرفعال است!🚫';

    public const ERROR_EXPIRED = '🚫 کد تخفیف وارد شده منقضی شده یا هنوز فعال نشده است!🚫';

    public const ERROR_PLAN_MISMATCH = '🚫 کد تخفیف وارد شده برای پلن انتخابی شما نمیباشد!🚫';

    /**
     * The panel could not be reached, so the code could not be verified.
     *
     * This is deliberately not the same as ERROR_INVALID: telling a user their
     * code is wrong when the panel is down would be false, and legacy had no
     * wording for it at all. Its `checkCoupon()` fed the error body to
     * `json_decode()` and then called `array_filter()` on null, which is a
     * fatal error in PHP 8, so the whole update died.
     */
    public const ERROR_UNAVAILABLE = '🚫 در حال حاضر امکان بررسی کد تخفیف وجود ندارد. لطفاً کمی بعد دوباره تلاش کنید.🚫';

    /**
     * Legacy logged this case and then fell through to `if ($discountResult)`,
     * which was false, so the user got no reply at all and the conversation
     * stalled. The seller misconfigured the coupon, not the user, so the
     * wording is ours; the intent (refuse, do not charge) is unchanged.
     */
    public const ERROR_NO_DISCOUNT_VALUE = '🚫 این کد تخفیف مقدار معتبری ندارد. لطفاً با پشتیبانی تماس بگیرید.🚫';

    public function __construct(
        private readonly ConnectixService $connectix,
    ) {}

    /**
     * Find a coupon by its code.
     *
     * Port of `checkCoupon()`. Legacy compared with `==`, so the code is
     * compared as a loose string to stay compatible with panel codes that
     * come back as either strings or numbers.
     *
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        foreach ($this->connectix->getCoupons() as $coupon) {
            if (($coupon['coupon_code'] ?? null) == $code) {
                return $coupon;
            }
        }

        return null;
    }

    /**
     * Validate a coupon against the plan the user is buying.
     *
     * Reproduces the ladder legacy walked in bot.php: unknown code, inactive
     * coupon, outside its date window, then plan restriction.
     *
     * Returns null when the coupon may be used, otherwise the exact Persian
     * rejection message legacy would have sent.
     */
    public function rejectionReason(string $code, int|string|null $planId = null): ?string
    {
        try {
            $coupon = $this->findByCode($code);
        } catch (ConnectixApiException $e) {
            // The lookup is on the critical path of a purchase, so an outage
            // becomes a message rather than an exception reaching the webhook.
            Log::error('Could not reach the panel to verify a coupon.', [
                'code' => $code,
                'error' => $e->getMessage(),
            ]);

            return self::ERROR_UNAVAILABLE;
        }

        if ($coupon === null) {
            return self::ERROR_INVALID;
        }

        // Legacy used `== true`, which also accepts "1" and 1 from the panel.
        if (($coupon['is_active'] ?? null) != true) {
            return self::ERROR_INACTIVE;
        }

        if (! $this->isWithinWindow($coupon)) {
            return self::ERROR_EXPIRED;
        }

        if (! $this->appliesToPlan($coupon, $planId)) {
            return self::ERROR_PLAN_MISMATCH;
        }

        return null;
    }

    /**
     * Whether the current time falls inside the coupon's optional window.
     *
     * Legacy compared two ISO-8601 strings lexically, and only treated a bound
     * as absent when it was literally null. Both details are kept: the panel
     * sends the same shape, so a string comparison is both what legacy did
     * and what agrees with it.
     *
     * @param  array<string, mixed>  $coupon
     */
    public function isWithinWindow(array $coupon, ?string $now = null): bool
    {
        $now ??= $this->legacyNow();

        $start = $coupon['start_date_text'] ?? null;
        $end = $coupon['end_date_text'] ?? null;

        $hasStarted = $start === null || $now >= $start;
        $notExpired = $end === null || $now <= $end;

        return $hasStarted && $notExpired;
    }

    /**
     * Whether the coupon is allowed for the plan being purchased.
     *
     * A coupon flagged `is_applied_to_all_plans` bypasses the check entirely.
     * Otherwise the plan must appear in `plans_ids`.
     *
     * @param  array<string, mixed>  $coupon
     */
    public function appliesToPlan(array $coupon, int|string|null $planId): bool
    {
        if (($coupon['is_applied_to_all_plans'] ?? null) == true) {
            return true;
        }

        if ($planId === null) {
            return false;
        }

        $planIds = $coupon['plans_ids'] ?? [];

        if (! is_array($planIds)) {
            return false;
        }

        foreach ($planIds as $candidate) {
            if ((string) $candidate === (string) $planId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply the coupon to a price.
     *
     * Port of the `apply` branch of `discount()`: a percentage is applied
     * first, otherwise a flat amount, and the price can never go below zero.
     *
     * Two deliberate hardenings over legacy:
     *   - the result is an int, because the price column is a string and a
     *     float such as 7000.000000000004 would leak into it;
     *   - the percentage branch is clamped at zero too. Legacy only clamped
     *     the flat amount branch, so a coupon above 100% produced a negative
     *     price that would have been charged as a credit.
     *
     * @param  array<string, mixed>  $coupon
     * @param  int|string  $originalPrice  Accepts a formatted price such as
     *                                     "120,000"; separators are stripped
     *                                     exactly as legacy did.
     */
    public function apply(array $coupon, int|string $originalPrice): DiscountResult
    {
        $original = $this->normalizePrice($originalPrice);

        $percent = $this->numericValue($coupon, 'per_cent');
        $amount = $this->numericValue($coupon, 'amount');

        if ($percent !== null) {
            $discountAmount = (int) round($original * $percent / 100);
        } elseif ($amount !== null) {
            $discountAmount = $amount;
        } else {
            Log::error('Coupon has no valid discount value.', [
                'coupon_code' => $coupon['coupon_code'] ?? null,
            ]);

            return new DiscountResult(
                originalPrice: $original,
                discountAmount: 0,
                finalPrice: $original,
                couponCode: (string) ($coupon['coupon_code'] ?? ''),
                valid: false,
            );
        }

        if ($discountAmount > $original) {
            Log::warning('Coupon discount exceeds the invoice; clamping to zero.', [
                'coupon_code' => $coupon['coupon_code'] ?? null,
                'original_price' => $original,
                'discount_amount' => $discountAmount,
            ]);

            $discountAmount = $original;
        }

        return new DiscountResult(
            originalPrice: $original,
            discountAmount: $discountAmount,
            finalPrice: $original - $discountAmount,
            couponCode: (string) ($coupon['coupon_code'] ?? ''),
            valid: true,
        );
    }

    /**
     * Strip thousands separators and cast to an int.
     */
    private function normalizePrice(int|string $price): int
    {
        return (int) str_replace(',', '', (string) $price);
    }

    /**
     * Read a discount field, requiring it to be non-empty and numeric.
     *
     * Legacy guarded with `!empty(...) && is_numeric(...)`, which also rejects
     * the string "0" as a discount because empty("0") is true.
     *
     * @param  array<string, mixed>  $coupon
     */
    private function numericValue(array $coupon, string $key): ?int
    {
        $raw = $coupon[$key] ?? null;

        if (empty($raw) || ! is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * The timestamp format legacy compared against the panel bounds.
     *
     * Note the `Z` suffix on a Tehran clock is what legacy produced; keeping
     * the exact same string is what makes the lexical comparison agree with
     * the running bot.
     */
    private function legacyNow(): string
    {
        return now('Asia/Tehran')->format('Y-m-d\TH:i:s.u\Z');
    }
}
