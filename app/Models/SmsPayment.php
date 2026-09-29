<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SmsPaymentType;
use Illuminate\Database\Eloquent\Model;

/**
 * An inbound bank SMS deposit awaiting (or matched to) an order.
 *
 * Legacy table: `sms_payments`. A row is created by the bank webhook and later
 * linked to a payment or a wallet transaction through `payment_id`.
 */
class SmsPayment extends Model
{
    protected $table = 'sms_payments';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'payment_type' => SmsPaymentType::class,
            'expired_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Legacy `smsPayment('check')` only matches rows that are still unmatched
     * and whose matching window has not elapsed.
     */
    public function scopeAvailable($query)
    {
        return $query
            ->whereNull('payment_id')
            ->where('expired_at', '>', now())
            ->where('created_at', '<=', now());
    }
}
