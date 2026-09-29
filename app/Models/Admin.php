<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An administrator of the panel.
 *
 * Legacy table: `admins`. Passwords are bcrypt hashed (`password_verify`).
 * The `token` column holds the Connectix seller bearer token used for
 * panel authentication and is therefore never exposed or logged.
 */
class Admin extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'admins';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => AdminRole::class,
        ];
    }

    /**
     * Clients owned by the admin's Telegram chat.
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'chat_id', 'chat_id');
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'chat_id', 'chat_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === AdminRole::Admin;
    }
}
