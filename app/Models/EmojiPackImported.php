<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A premium emoji pack imported from a sticker set.
 *
 * Feature-fork table: `emoji_packs_imported` (EmojiPackImportService).
 * Populated automatically when an admin sends a premium emoji to the bot.
 */
class EmojiPackImported extends Model
{
    protected $table = 'emoji_packs_imported';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }
}
