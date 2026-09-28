<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Connectix panel client linked to a Telegram account.
 *
 * Legacy table: `clients`. The primary key is the external UUID assigned by the
 * Connectix seller API, therefore `$incrementing` is disabled and the key is a
 * string.
 */
class Client extends Model
{
    protected $table = 'clients';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'count_of_devices' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The owning Telegram user. Legacy uses `ON DELETE SET NULL`, so this may
     * legitimately be absent for clients imported from the seller panel.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
