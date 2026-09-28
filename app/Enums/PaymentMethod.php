<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Payment method recorded on the `payments` table and derived from the
 * `pay_*` callback prefix in legacy.
 */
enum PaymentMethod: string
{
    case Card = 'card';
    case Wallet = 'wallet';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'کارت به کارت',
            self::Wallet => 'کیف پول',
        };
    }

    /**
     * The callback prefix used by `pay_card:<price>` / `pay_wallet:<price>`.
     */
    public function callbackPrefix(): string
    {
        return $this->value;
    }
}
