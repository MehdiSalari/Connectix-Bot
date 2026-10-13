<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A failed admin-panel login attempt.
 *
 * Feature-fork table: `login_attempts`. `identifier` is keyed either
 * "ip:<addr>" or "email:<address>" so the throttle can cover both a single
 * hammering source and a distributed attempt against one account.
 */
class LoginAttempt extends Model
{
    protected $table = 'login_attempts';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }
}
