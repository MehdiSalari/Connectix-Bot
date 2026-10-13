<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One admin action recorded in the panel's activity trail.
 *
 * Feature-fork table: `admin_activity`. `subject_type`/`subject_id` name
 * what was acted on; the fork's "who did what, from where" record.
 */
class AdminActivity extends Model
{
    protected $table = 'admin_activity';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
