<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One audited status change on a financial record.
 *
 * Feature-fork table: `transactions_audit_log`. `actor_id`/`actor_name`
 * identify the admin (or system) that moved `previous_status` to
 * `new_status`; `details` carries any free-form context.
 */
class TransactionAuditLog extends Model
{
    protected $table = 'transactions_audit_log';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
