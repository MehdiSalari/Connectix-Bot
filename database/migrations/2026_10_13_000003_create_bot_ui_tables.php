<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature-fork bot tables (group C).
 *
 * Mirrors the database-driven bot UI the feature fork builds: customizable
 * menu buttons (with premium-emoji, color and screen support), the central
 * emoji registry, imported premium emoji packs, and the broadcast engine
 * (plain + scheduled jobs, plus the per-recipient ledger that lets a
 * durable broadcast resume from where it stopped).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createBotButtons();
        $this->createBotEmojis();
        $this->createEmojiPacksImported();
        $this->createBroadcastLogs();
        $this->createBroadcastRecipients();
        $this->createScheduledBroadcasts();
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_broadcasts');
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcast_logs');
        Schema::dropIfExists('emoji_packs_imported');
        Schema::dropIfExists('bot_emojis');
        Schema::dropIfExists('bot_buttons');
    }

    private function createBotButtons(): void
    {
        Schema::create('bot_buttons', function (Blueprint $table): void {
            $table->id();
            $table->string('button_key', 64)->unique();
            $table->string('default_label');
            $table->string('current_label');
            $table->string('emoji', 100)->default('');
            $table->string('custom_emoji_id', 100)->default('');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->integer('row_num')->default(1);
            $table->integer('col_num')->default(1);
            // 'internal' | 'buy' | 'open_url' | ... — what tapping the button does.
            $table->string('action_type', 64)->default('internal');
            $table->string('button_color', 20)->nullable();
            // 'main_menu' | 'buy_screen' | 'download_software' | ...
            $table->string('screen', 32)->default('main_menu');
            $table->text('link_url')->nullable();
            $table->boolean('hide_after_test')->default(false);
        });
    }

    private function createBotEmojis(): void
    {
        Schema::create('bot_emojis', function (Blueprint $table): void {
            $table->id();
            $table->string('emoji_key', 64)->unique();
            $table->string('name');
            $table->string('section', 64);
            $table->string('emoji_char', 32);
            $table->string('custom_emoji_id', 100)->default('');
            $table->string('description')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function createEmojiPacksImported(): void
    {
        Schema::create('emoji_packs_imported', function (Blueprint $table): void {
            $table->id();
            $table->string('set_name');
            $table->string('custom_emoji_id', 100)->unique();
            $table->string('emoji_char', 32);
            $table->timestamp('imported_at')->nullable();
        });
    }

    private function createBroadcastLogs(): void
    {
        Schema::create('broadcast_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('admin_id')->nullable();
            // 'SEND' | 'FORWARD' | 'COPY'
            $table->string('type', 32)->default('SEND');
            $table->text('content')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->integer('total_count')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('blocked_count')->default(0);
            $table->string('status', 32)->default('COMPLETED');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('completed_at')->nullable();
        });
    }

    private function createBroadcastRecipients(): void
    {
        Schema::create('broadcast_recipients', function (Blueprint $table): void {
            $table->integer('broadcast_id');
            $table->integer('seq');
            $table->string('chat_id');

            $table->primary(['broadcast_id', 'seq']);
        });
    }

    private function createScheduledBroadcasts(): void
    {
        Schema::create('scheduled_broadcasts', function (Blueprint $table): void {
            $table->id();
            $table->string('admin_chat_id');
            $table->string('source_chat_id');
            $table->string('source_message_id');
            $table->dateTime('scheduled_at');
            $table->string('status', 32)->default('PENDING');
            $table->integer('broadcast_log_id')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('executed_at')->nullable();

            $table->index(['status', 'scheduled_at']);
        });
    }
};
