<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single row of win-back campaign settings.
 *
 * Feature-fork table: `winback_settings` (WinbackService). The campaign
 * targets past paying customers silent for `inactive_days` days;
 * `message_text` supports {name} placeholders and `cron_secret`
 * authenticates the cron that runs it.
 */
class WinbackSetting extends Model
{
    protected $table = 'winback_settings';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = [
        'cron_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'inactive_days' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
