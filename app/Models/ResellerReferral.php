<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user who registered through a reseller's referral code.
 *
 * Feature-fork table: `reseller_referrals`. One row per referred chat;
 * `referred_user_id` is the local users.id when the referred chat had one.
 */
class ResellerReferral extends Model
{
    protected $table = 'reseller_referrals';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'reseller_id');
    }
}
