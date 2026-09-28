<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a `wallet_transactions` row.
 */
enum WalletTransactionStatus: string
{
    case Pending = 'PENDING';
    case Success = 'SUCCESS';
    case CanceledByUser = 'CANCLED_BY_USER';
    case RejectedByAdmin = 'REJECTED_BY_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'در انتظار',
            self::Success => 'موفق',
            self::CanceledByUser => 'لغو شده',
            self::RejectedByAdmin => 'رد شده',
        };
    }

    public function labelForAdmin(): string
    {
        return match ($this) {
            self::Pending => 'در انتظار',
            self::Success => 'تایید شده',
            self::CanceledByUser => 'لغو شده',
            self::RejectedByAdmin => 'رد شده',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending => '⏳',
            self::Success => '✅',
            self::CanceledByUser => '🚫',
            self::RejectedByAdmin => '❌',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }
}
