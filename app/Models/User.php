<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Telegram user of the bot.
 *
 * Legacy table: `users` (chat_id is the natural business key).
 */
class User extends Model
{
    use HasFactory;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'test' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Connections are keyed by chat_id, not by the local user id.
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'user_id');
    }

    /**
     * Clients attached to this user by chat_id. Used where the local
     * `clients.user_id` back-reference may be missing.
     */
    public function clientsByChatId(): HasMany
    {
        return $this->hasMany(Client::class, 'chat_id', 'chat_id');
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'chat_id', 'chat_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'chat_id', 'chat_id');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'chat_id', 'chat_id');
    }

    /**
     * Whether the user already consumed the free trial.
     */
    public function hasUsedTest(): bool
    {
        return $this->test === true;
    }
}
