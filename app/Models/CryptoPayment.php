<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A TRX/TON payment awaiting on-chain verification.
 *
 * Feature-fork table: `crypto_payments` (CryptoPayService). `expected_units`
 * /`received_units` are in the network's smallest unit; the unique
 * (network, tx_hash) index enforces "one on-chain transfer pays one order".
 * `attempts` counts verification retries for a submitted hash.
 */
class CryptoPayment extends Model
{
    protected $table = 'crypto_payments';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'wallet_tx_id' => 'integer',
            'amount_toman' => 'integer',
            'expected_units' => 'integer',
            'received_units' => 'integer',
            'attempts' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'last_check_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function isWaiting(): bool
    {
        return $this->status === 'WAITING';
    }
}
