<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An approved (or pending) reseller of the bot.
 *
 * Feature-fork table: `resellers` — the affiliate/representative registry.
 * `ref_code` is the code a customer quotes when registering; `chat_id` is
 * the reseller's Telegram chat. The test-account quota columns mirror the
 * fork's reseller free-trial limits.
 */
class Reseller extends Model
{
    protected $table = 'resellers';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
            'balance' => 'decimal:2',
            'total_earned' => 'decimal:2',
            'test_used' => 'integer',
        ];
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(ResellerReferral::class, 'reseller_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ResellerCommission::class, 'reseller_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(ResellerPayout::class, 'reseller_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
