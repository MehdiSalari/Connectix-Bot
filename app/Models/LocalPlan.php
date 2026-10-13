<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan stored locally instead of on the Connectix seller panel.
 *
 * Feature-fork table: `local_plans` (PanelService). `price` is whole toman;
 * `is_free` marks the free-test plan of a panel; `group_name` buckets plans
 * in the purchase menu.
 */
class LocalPlan extends Model
{
    protected $table = 'local_plans';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'days' => 'integer',
            'traffic_gb' => 'decimal:2',
            'devices' => 'integer',
            'panel_id' => 'integer',
            'is_free' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class, 'panel_id');
    }
}
