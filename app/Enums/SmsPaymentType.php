<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an inbound bank SMS deposit was matched against. Persisted on
 * `sms_payments.payment_type`.
 */
enum SmsPaymentType: string
{
    case Buy = 'buy';
    case Wallet = 'wallet';

    public function label(): string
    {
        return match ($this) {
            self::Buy => 'خرید سرویس',
            self::Wallet => 'افزایش موجودی کیف پول',
        };
    }
}
