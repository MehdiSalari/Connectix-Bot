<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One auditable commission entry per settled payment.
 *
 * Feature-fork table: `reseller_commissions`. The rate is snapshotted at
 * settlement time so later rate changes never rewrite history.
 */
class ResellerCommission extends Model
{
    protected $table = 'reseller_commissions';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'order_amount' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'reseller_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }
}
