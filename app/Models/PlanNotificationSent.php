<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dedup ledger for plan expiry/usage notifications.
 *
 * Feature-fork table: `plan_notifications_sent`. Unique on
 * (client_id, notif_type, cycle_key): cycle_key is the plan's expire date,
 * so a renewal (new expire date) automatically allows reminders to fire
 * again without a manual reset.
 */
class PlanNotificationSent extends Model
{
    protected $table = 'plan_notifications_sent';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
