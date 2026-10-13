<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One broadcast job and its running counters.
 *
 * Feature-fork table: `broadcast_logs` (BroadcastService). A row doubles as
 * the durable broadcast state: `status` moves from RUNNING to COMPLETED /
 * STOPPED, and resumable jobs keep their payload on the row.
 */
class BroadcastLog extends Model
{
    protected $table = 'broadcast_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'total_count' => 'integer',
            'success_count' => 'integer',
            'failed_count' => 'integer',
            'blocked_count' => 'integer',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class, 'broadcast_id');
    }

    public function scheduledBroadcasts(): HasMany
    {
        return $this->hasMany(ScheduledBroadcast::class, 'broadcast_log_id');
    }
}
