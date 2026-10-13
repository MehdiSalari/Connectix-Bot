<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single row of custom volume/duration plan settings.
 *
 * Feature-fork table: `custom_plan_settings` (CustomPlanService). The user
 * picks any GB/day combo within the min/max bounds and the price is
 * computed from the per-GB and per-day rates. Prices are whole-toman
 * DECIMAL(12,0) values.
 */
class CustomPlanSetting extends Model
{
    protected $table = 'custom_plan_settings';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price_per_gb' => 'decimal:0',
            'price_per_day' => 'decimal:0',
            'min_gb' => 'decimal:2',
            'max_gb' => 'decimal:2',
            'min_days' => 'integer',
            'max_days' => 'integer',
            'default_devices' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function priceFor(float $gb, int $days): int
    {
        return (int) round($gb * (float) $this->price_per_gb + $days * (float) $this->price_per_day);
    }
}
