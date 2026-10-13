<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One reward in the loyalty catalog.
 *
 * Feature-fork table: `loyalty_rewards` (LoyaltyService). `kind` decides
 * what a redemption grants (percent discount, fixed amount, or a plan);
 * `stock` limits total redemptions and `per_user_month` the per-user
 * monthly rate. Datetime bounds are the fork's 'Y-m-d H:i:s' strings.
 */
class LoyaltyReward extends Model
{
    protected $table = 'loyalty_rewards';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'cost' => 'integer',
            'redeemed_count' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class, 'reward_id');
    }

    public function hasStock(): bool
    {
        return $this->stock === null || $this->stock > $this->redeemed_count;
    }
}
