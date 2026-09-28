<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Reason a wallet balance moved. Persisted on `wallet_transactions.operation`.
 */
enum WalletOperation: string
{
    case Increase = 'INCREASE';
    case Decrease = 'DECREASE';

    public function label(): string
    {
        return match ($this) {
            self::Increase => 'افزایش',
            self::Decrease => 'کاهش',
        };
    }
}
