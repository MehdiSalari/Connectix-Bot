<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A "become a representative" request awaiting admin review.
 *
 * Feature-fork table: `reseller_requests`. Approval flips the applicant's
 * row in `resellers` to ACTIVE; this table only records the request itself
 * and the wallet balance the applicant had when asking.
 */
class ResellerRequest extends Model
{
    protected $table = 'reseller_requests';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'wallet_balance_at_request' => 'decimal:2',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }
}
