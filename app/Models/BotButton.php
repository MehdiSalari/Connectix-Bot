<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One customizable bot keyboard button.
 *
 * Feature-fork table: `bot_buttons` (ButtonService). The main-menu keyboard
 * is fully DB-driven once this table has rows: `current_label` is what the
 * user sees, `screen` groups buttons per keyboard ('main_menu',
 * 'buy_screen', 'download_software', ...), and `hide_after_test` removes
 * the free-trial button once a user has used theirs.
 */
class BotButton extends Model
{
    protected $table = 'bot_buttons';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'row_num' => 'integer',
            'col_num' => 'integer',
            'hide_after_test' => 'boolean',
        ];
    }
}
