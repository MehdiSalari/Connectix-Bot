<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message inside a support ticket.
 *
 * Feature-fork table: `ticket_messages`. `sender_type` distinguishes the
 * customer from the answering admin; `attachment` carries the fork's
 * attachment reference when the message had one.
 */
class TicketMessage extends Model
{
    protected $table = 'ticket_messages';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
