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

    /**
     * The deposit's kind, read from the raw column rather than the enum cast:
     * `payment_type` is cast to an enum, and a legacy row holding a value the
     * enum does not know would throw while merely rendering the list.
     */
    public function type(): ?SmsPaymentType
    {
        return SmsPaymentType::tryFrom((string) ($this->getRawOriginal('payment_type') ?? ''));
    }

    public function typeLabel(): ?string
    {
        return $this->type()?->label();
    }

    /** Whether the deposit found what it was matched against (or still may). */
    public function isMatched(): bool
    {
        return (string) $this->getRawOriginal('payment_id') !== '';
    }

    /**
     * [label, tone] for the status badge: a matched deposit has settled by
     * definition; an unmatched one is either still inside its matching window
     * or already past it.
     *
     * @return array{0: string, 1: string}
     */
    public function status(): array
    {
        if ($this->isMatched()) {
            return ['تأیید شده', 'ok'];
        }

        if ($this->expired_at !== null && $this->expired_at->isPast()) {
            return ['منقضی', 'no'];
        }

        return ['در انتظار تطبیق', 'wait'];
    }
}
