<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support ticket opened from the bot.
 *
 * Feature-fork table: `tickets`. `ticket_number` is the human-facing id
 * shown in the conversation; `department` defaults to the fork's general
 * support department label.
 */
class Ticket extends Model
{
    protected $table = 'tickets';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'OPEN';
    }
}
