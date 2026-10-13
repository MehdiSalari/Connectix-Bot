<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature-fork notification and lifecycle schema (group F).
 *
 * Mirrors the expiry/usage reminder system, the win-back campaign, the
 * one-time welcome credit audit log, and the local coupon redemption cap
 * the feature fork adds on top of the legacy schema. Dedup logs are keyed
 * naturally (client + notification + cycle, chat + cycle) so a new purchase
 * cycle re-opens notifications without a manual reset.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createNotificationSettings();
        $this->createPlanNotificationsSent();
        $this->createWinbackSettings();
        $this->createWinbackLogs();
        $this->createWinbackSent();
        $this->createWelcomeCreditLog();
        $this->createCouponRestrictions();
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_restrictions');
        Schema::dropIfExists('welcome_credit_log');
        Schema::dropIfExists('winback_sent');
        Schema::dropIfExists('winback_logs');
        Schema::dropIfExists('winback_settings');
        Schema::dropIfExists('plan_notifications_sent');
        Schema::dropIfExists('notification_settings');
    }

    private function createNotificationSettings(): void
    {
        Schema::create('notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active')->default(true);
            // Comma-separated day markers, e.g. '3,1'.
            $table->string('expiry_days_before', 50)->default('3,1');
            $table->string('usage_percent_thresholds', 50)->default('80,90');
            $table->string('cron_secret', 64)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function createPlanNotificationsSent(): void
    {
        Schema::create('plan_notifications_sent', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id');
            $table->string('notif_type', 30);
            // The cycle this notification belongs to (its expire date), so a
            // renewal automatically allows reminders to fire again.
            $table->string('cycle_key', 100);
            $table->timestamp('sent_at')->nullable()->useCurrent();

            $table->unique(['client_id', 'notif_type', 'cycle_key']);
        });
    }

    private function createWinbackSettings(): void
    {
        Schema::create('winback_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->integer('inactive_days')->default(30);
            $table->text('message_text')->nullable();
            $table->string('coupon_code', 100)->nullable();
            $table->string('cron_secret', 64)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function createWinbackLogs(): void
    {
        Schema::create('winback_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id');
            $table->text('message')->nullable();
            $table->string('coupon_code', 100)->nullable();
            $table->string('status', 32);
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createWinbackSent(): void
    {
        Schema::create('winback_sent', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id');
            // The customer's last-payment date: a later purchase opens a
            // fresh win-back cycle for the same chat.
            $table->string('cycle_key', 20);
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->unique(['chat_id', 'cycle_key']);
            $table->index('chat_id');
        });
    }

    private function createWelcomeCreditLog(): void
    {
        Schema::create('welcome_credit_log', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id')->unique();
            $table->integer('amount')->default(0);
            $table->timestamp('granted_at')->nullable()->useCurrent();
        });
    }

    private function createCouponRestrictions(): void
    {
        Schema::create('coupon_restrictions', function (Blueprint $table): void {
            $table->string('coupon_code', 100)->primary();
            // Local usage cap; the remote Connectix API handles expiry itself.
            $table->integer('usage_limit')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }
};
