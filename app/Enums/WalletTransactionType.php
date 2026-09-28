<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What triggered a wallet transaction. Persisted on
 * `wallet_transactions.type`.
 */
enum WalletTransactionType: string
{
    case Buy = 'BUY';
    case CardToCard = 'CARD_TO_CARD';
    case DoneByAdmin = 'DONE_BY_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::Buy => 'خرید',
            self::CardToCard => 'کارت به کارت',
            self::DoneByAdmin => 'توسط ادمین',
        };
    }
}
