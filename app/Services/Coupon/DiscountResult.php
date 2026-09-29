<?php

declare(strict_types=1);

namespace App\Services\Coupon;

/**
 * The outcome of applying a coupon to an invoice.
 *
 * Legacy returned the final price as a bare number from `discount()` and threw
 * the discount amount away, then recomputed a formatted copy of it for the
 * confirmation message. Both values are kept here so the caller does not have
 * to derive one from the other.
 */
final class DiscountResult
{
    public function __construct(
        public readonly int $originalPrice,
        public readonly int $discountAmount,
        public readonly int $finalPrice,
        public readonly string $couponCode,
        public readonly bool $valid,
    ) {}

    /**
     * Whether the coupon can actually reduce the price.
     *
     * A reseller can create a coupon with neither a percentage nor an amount;
     * legacy treated that as a failure and left the user waiting.
     */
    public function isUsable(): bool
    {
        return $this->valid;
    }

    /**
     * The discount as legacy rendered it in the confirmation message.
     */
    public function formattedAmount(): string
    {
        return number_format($this->discountAmount);
    }
}
