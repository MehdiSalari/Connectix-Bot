<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Payment settlement status.
 *
 * The legacy `payments.is_paid` column is a nullable VARCHAR and uses three
 * distinct states. `null` means "awaiting admin decision", which is the state
 * the receipt review flow depends on, so the tri-state mapping is preserved.
 */
enum PaymentStatus: string
{
    case Pending = '';
    case Rejected = '0';
    case Paid = '1';

    /**
     * Human readable Persian label, matching legacy payroll captions.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'در انتظار',
            self::Rejected => 'رد شده',
            self::Paid => 'تایید شده',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending => '⏳',
            self::Rejected => '❌',
            self::Paid => '✅',
        };
    }

    /**
     * Resolve the status from the raw database value.
     */
    public static function fromDatabase(mixed $value): self
    {
        return match ((string) $value) {
            '1' => self::Paid,
            '0' => self::Rejected,
            default => self::Pending,
        };
    }

    /**
     * Legacy `paycheck()` only refuses to act when the value is not null.
     */
    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
