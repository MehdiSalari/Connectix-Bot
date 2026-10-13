<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One win-back message sent to a quiet customer.
 *
 * Feature-fork table: `winback_logs` (WinbackService). `status` records the
 * outcome of the send; `coupon_code` the discount attached to the message.
 */
class WinbackLog extends Model
{
    protected $table = 'winback_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
