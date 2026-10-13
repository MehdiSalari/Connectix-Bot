<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One configured VPN panel the bot provisions accounts on.
 *
 * Feature-fork table: `panels` (PanelService) — multi-panel support for
 * Connectix, Marzban, 3x-ui, Remnawave and Hiddify. `secret` is encrypted
 * at the application layer before storage and must never be exposed;
 * `extra` is a JSON blob of panel-type-specific settings.
 */
class Panel extends Model
{
    protected $table = 'panels';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'extra' => 'array',
        ];
    }

    public function plans(): HasMany
    {
        return $this->hasMany(LocalPlan::class, 'panel_id');
    }
}
