<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A bank card number seen paying from a Telegram chat.
 *
 * Feature-fork table: `payment_cards` (CardTrackService). Rows are appended
 * by the receipt flow; several distinct cards under one chat is the
 * multi-account signal the fork's card tracker acts on.
 */
class PaymentCard extends Model
{
    protected $table = 'payment_cards';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
