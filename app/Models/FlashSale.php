<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A time-boxed discount on one plan.
 *
 * Feature-fork table: `flash_sales` (FlashSaleService). `starts_at`/`ends_at`
 * are Tehran-local 'Y-m-d H:i:s' strings compared lexically against the
 * fork's clock — they are intentionally plain strings, not datetime casts.
 */
class FlashSale extends Model
{
    protected $table = 'flash_sales';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'discount_value' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Whether the sale's window covers the given 'Y-m-d H:i:s' moment.
     */
    public function covers(string $tehranNow): bool
    {
        return $this->is_active
            && $this->starts_at <= $tehranNow
            && $this->ends_at > $tehranNow;
    }
}
