<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's wallet balance.
 *
 * Legacy table: `wallets`, keyed by `chat_id`. The balance column is a string
 * in the legacy schema; it is treated as an integer everywhere in the service
 * layer to keep arithmetic correct.
 */
class Wallet extends Model
{
    protected $table = 'wallets';

    public $timestamps = false;

    protected $guarded = [];

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }

    /**
     * Current balance as an integer.
     */
    public function balanceAmount(): int
    {
        return (int) $this->balance;
    }
}
