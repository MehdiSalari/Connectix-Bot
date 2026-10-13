<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reward a user redeemed with loyalty points.
 *
 * Feature-fork table: `loyalty_redemptions` (LoyaltyService). `code` is the
 * unique voucher handed to the user; `expires_at`/`used_at`/`created_at`
 * are the fork's 'Y-m-d H:i:s' strings, not datetime casts.
 */
class LoyaltyRedemption extends Model
{
    protected $table = 'loyalty_redemptions';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reward_id' => 'integer',
            'points' => 'integer',
            'value' => 'integer',
        ];
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'reward_id');
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
