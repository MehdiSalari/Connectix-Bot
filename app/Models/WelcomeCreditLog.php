<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One-time welcome credit audit log.
 *
 * Feature-fork table: `welcome_credit_log`. Unique on chat_id: the grant is
 * once per user, ever. Existence of the row (not its amount) is the guard.
 */
class WelcomeCreditLog extends Model
{
    protected $table = 'welcome_credit_log';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'granted_at' => 'datetime',
        ];
    }
}
