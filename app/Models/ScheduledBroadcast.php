<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A broadcast composed now and queued for a future send time.
 *
 * Feature-fork table: `scheduled_broadcasts`. Picked up and executed by the
 * fork's broadcast cron; `broadcast_log_id` links the executed result row.
 */
class ScheduledBroadcast extends Model
{
    protected $table = 'scheduled_broadcasts';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'created_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function broadcastLog(): BelongsTo
    {
        return $this->belongsTo(BroadcastLog::class, 'broadcast_log_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }
}
