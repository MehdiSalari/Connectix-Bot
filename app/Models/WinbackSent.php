<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dedup ledger for the win-back campaign.
 *
 * Feature-fork table: `winback_sent`. Unique on (chat_id, cycle_key) where
 * cycle_key is the customer's last-payment date, so a later purchase opens
 * a fresh win-back cycle for the same chat.
 */
class WinbackSent extends Model
{
    protected $table = 'winback_sent';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
