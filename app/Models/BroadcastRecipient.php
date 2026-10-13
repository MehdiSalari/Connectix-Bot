<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One chat in a broadcast's audience ledger.
 *
 * Feature-fork table: `broadcast_recipients`. Composite primary key
 * (broadcast_id, seq) — `seq` is the position in the send order, which is
 * what lets a crashed worker resume from the right chat. No auto-increment
 * id exists, so `$incrementing` is disabled.
 */
class BroadcastRecipient extends Model
{
    protected $table = 'broadcast_recipients';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'broadcast_id' => 'integer',
            'seq' => 'integer',
        ];
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(BroadcastLog::class, 'broadcast_id');
    }
}
