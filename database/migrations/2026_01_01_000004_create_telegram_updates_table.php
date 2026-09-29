<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ledger of the Telegram updates this installation already handled.
 *
 * Legacy had no such ledger, so Telegram's redelivery of an unacknowledged
 * update re-ran the whole handler chain - including a second order write or a
 * second confirmation message. `update_id` is a unique, increasing integer per
 * bot, so the primary key is the deduplication: the second delivery of the same
 * id cannot be inserted and is answered without touching a handler.
 *
 * The table is deliberately tiny and self-pruning: `handled_at` is indexed and
 * `connectix:prune` (scheduled hourly) deletes rows that are old enough that
 * Telegram can no longer redeliver them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telegram_updates')) {
            return;
        }

        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->unsignedBigInteger('update_id')->primary();
            $table->timestamp('handled_at')->useCurrent();

            $table->index('handled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
