<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single row of expiry-reminder and usage-warning settings.
 *
 * Feature-fork table: `notification_settings` (check_notifications cron).
 * The threshold columns hold comma-separated markers ('3,1' days before
 * expiry, '80,90' percent used); `cron_secret` authenticates cron calls.
 */
class NotificationSetting extends Model
{
    protected $table = 'notification_settings';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = [
        'cron_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, int>
     */
    public function expiryDaysBefore(): array
    {
        return array_map(intval(...), array_filter(explode(',', (string) $this->expiry_days_before), strlen(...)));
    }

    /**
     * @return array<int, int>
     */
    public function usagePercentThresholds(): array
    {
        return array_map(intval(...), array_filter(explode(',', (string) $this->usage_percent_thresholds), strlen(...)));
    }
}
