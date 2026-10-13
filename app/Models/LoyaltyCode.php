<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A discount code an admin pre-issued for a reward.
 *
 * Feature-fork table: `loyalty_codes` (LoyaltyService). Codes are unique
 * per reward; `assigned_to` records the chat a code was handed to.
 */
class LoyaltyCode extends Model
{
    protected $table = 'loyalty_codes';

    public $timestamps = false;

    protected $guarded = [];

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'reward_id');
    }

    public function isAssigned(): bool
    {
        return $this->assigned_to !== null;
    }
}
