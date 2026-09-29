<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Models\Payment;
use App\Models\User;

/**
 * What happened when an order was provisioned.
 *
 * The three outcomes are the three things a caller has to answer differently:
 * the admin needs a caption per outcome, the buyer only hears about the last
 * two, and a retry is only meaningful when the order is still open.
 */
final class ProvisionResult
{
    public const PROVISIONED = 'provisioned';

    public const ALREADY_DECIDED = 'already_decided';

    public const FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $client  The panel's view of the account.
     */
    private function __construct(
        public readonly string $status,
        public readonly Payment $payment,
        public readonly ?User $user = null,
        public readonly array $client = [],
        public readonly ?string $reason = null,
    ) {}

    /**
     * The account was created or renewed and the order is now paid.
     *
     * @param  array<string, mixed>  $client
     */
    public static function provisioned(Payment $payment, User $user, array $client): self
    {
        return new self(self::PROVISIONED, $payment, $user, $client);
    }

    /**
     * The order had already been decided, so nothing was changed.
     *
     * This is the duplicate press: the account was provisioned by the run that
     * decided the order.
     */
    public static function alreadyDecided(Payment $payment): self
    {
        return new self(self::ALREADY_DECIDED, $payment);
    }

    /**
     * The order is still open, and the reason is logged and reported.
     */
    public static function failed(Payment $payment, string $reason): self
    {
        return new self(self::FAILED, $payment, reason: $reason);
    }

    public function isProvisioned(): bool
    {
        return $this->status === self::PROVISIONED;
    }

    public function isAlreadyDecided(): bool
    {
        return $this->status === self::ALREADY_DECIDED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }

    /**
     * Whether the order may still be provisioned again.
     */
    public function isRetryable(): bool
    {
        return $this->isFailed();
    }
}
