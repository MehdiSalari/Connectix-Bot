<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One handled Telegram update, keyed by `update_id`.
 *
 * The row is written before the update is handed to the gateway and doubles as
 * the "already processed" check: a redelivered update fails to insert, which is
 * how a Telegram retry is turned into a cheap no-op instead of a second order.
 *
 * @property int $update_id
 */
class HandledUpdate extends Model
{
    protected $table = 'telegram_updates';

    protected $primaryKey = 'update_id';

    protected $keyType = 'int';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Record the update and report whether it is new.
     *
     * A duplicate is detected by the primary key rather than by a
     * read-then-write, so two simultaneous redeliveries of the same update
     * still settle on exactly one handler run.
     */
    public static function claim(int $updateId): bool
    {
        if ($updateId <= 0) {
            return true;
        }

        return static::query()->insertOrIgnore([
            'update_id' => $updateId,
            'handled_at' => now(),
        ]) > 0;
    }
}
