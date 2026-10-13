<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The imported premium-emoji packs gain the fork's favourite flag.
 *
 * The fork's setup/migrations.php adds `is_favorite` to
 * `emoji_packs_imported` after the fact (emojis/emoji_favorite.php stars an
 * emoji so it sorts first in the picker); wave 1's bot-ui migration predates
 * that feature, so it lands here additively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emoji_packs_imported', function (Blueprint $table): void {
            $table->boolean('is_favorite')->default(false)->after('emoji_char');
        });
    }

    public function down(): void
    {
        Schema::table('emoji_packs_imported', function (Blueprint $table): void {
            $table->dropColumn('is_favorite');
        });
    }
};
