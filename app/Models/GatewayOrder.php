<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order submitted to an online gateway.
 *
 * Feature-fork table: `gateway_orders` (OnlineGatewayService). `token` is
 * the bot's own unique order reference; `authority`/`ref_id`/`pay_url` are
 * the gateway's handles. `amount_toman` is the requested price and
 * `amount_sent` what was actually sent to the gateway (after rial
 * conversion, when the gateway's unit is rial).
 */
class GatewayOrder extends Model
{
    protected $table = 'gateway_orders';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'wallet_tx_id' => 'integer',
            'amount_toman' => 'integer',
            'amount_sent' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function isPaid(): bool
    {
        return $this->status === 'PAID' || $this->paid_at !== null;
    }
}
