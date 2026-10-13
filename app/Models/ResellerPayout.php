<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A settlement/withdrawal request from a reseller.
 *
 * Feature-fork table: `reseller_payouts`. `card_name`/`card_number`/
 * `sheba_number` are snapshotted from the reseller's profile at request
 * time, `processed_by` records which admin settled it.
 */
class ResellerPayout extends Model
{
    protected $table = 'reseller_payouts';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'reseller_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }
}
