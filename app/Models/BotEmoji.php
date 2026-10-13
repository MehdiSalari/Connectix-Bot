<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry of the centralized emoji registry.
 *
 * Feature-fork table: `bot_emojis` (EmojiService). `emoji_key` is the
 * logical name code references (menu_buy, msg_success, ...), `emoji_char`
 * the fallback glyph and `custom_emoji_id` the premium Telegram emoji that
 * overrides it.
 */
class BotEmoji extends Model
{
    protected $table = 'bot_emojis';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
        ];
    }
}
