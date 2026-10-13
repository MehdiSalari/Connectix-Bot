<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A transaction hash a user submitted, remembered forever.
 *
 * Feature-fork table: `crypto_hashes` (CryptoPayService). The unique
 * (network, hash) index means any given hash can only ever be submitted
 * once, whatever its outcome — this table is the replay guard.
 */
class CryptoHash extends Model
{
    protected $table = 'crypto_hashes';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
