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
    case Loyalty = 'loyalty';
    case Gateway = 'gateway';
    case Custom = 'custom';
    case Zarinpal = 'zarinpal';
    case Nextpay = 'nextpay';
    case Nowpayments = 'nowpayments';
    case CryptoTon = 'crypto_ton';
    case CryptoTrx = 'crypto_trx';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'کارت به کارت',
            self::Wallet => 'کیف پول',
            self::Loyalty => 'جایزه امتیاز',
            self::Gateway => 'درگاه آنلاین',
            self::Custom => 'سفارش اختصاصی',
            self::Zarinpal => 'درگاه زرینپال',
            self::Nextpay => 'درگاه نکست‌پی',
            self::Nowpayments => 'درگاه NOWPayments',
            self::CryptoTon => 'کریپتو (TON)',
            self::CryptoTrx => 'کریپتو (TRX)',
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
