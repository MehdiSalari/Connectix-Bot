<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletOperation;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single wallet ledger entry.
 *
 * Legacy table: `wallet_transactions`. Legacy does not record which admin
 * performed a `DONE_BY_ADMIN` adjustment; that is left nullable here and
 * filled in when the panel performs the action.
 */
class WalletTransaction extends Model
{
    protected $table = 'wallet_transactions';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'operation' => WalletOperation::class,
            'status' => WalletTransactionStatus::class,
            'type' => WalletTransactionType::class,
            'created_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }
}
