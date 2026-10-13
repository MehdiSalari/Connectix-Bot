<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A local usage cap for one coupon code.
 *
 * Feature-fork table: `coupon_restrictions`. The remote Connectix API
 * handles coupon expiry itself; what it does not expose is a redemption
 * cap, so this table adds one by counting matching rows in `payments`.
 * The coupon code is the primary key.
 */
class CouponRestriction extends Model
{
    protected $table = 'coupon_restrictions';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'coupon_code';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'usage_limit' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
