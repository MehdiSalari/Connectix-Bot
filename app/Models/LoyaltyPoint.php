<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One loyalty points ledger entry for a chat.
 *
 * Feature-fork table: `loyalty_points` (LoyaltyService). The unique
 * (type, ref) pair is the idempotency key: one ledger row per event.
 * `expires_at`/`created_at` are the fork's 'Y-m-d H:i:s' strings compared
 * lexically — intentionally plain strings, not datetime casts.
 */
class LoyaltyPoint extends Model
{
    protected $table = 'loyalty_points';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'remaining' => 'integer',
        ];
    }

    public function hasRemaining(): bool
    {
        return $this->remaining > 0;
    }
}
