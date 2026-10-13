<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature-fork loyalty and multi-panel schema (group E).
 *
 * Mirrors the loyalty-points program (points ledger, rewards catalog,
 * redemptions, admin-issued codes), the multi-panel support rows
 * (Marzban / 3x-ui / Remnawave / Hiddify), the locally stored plan list,
 * and the custom volume/duration plan settings. Loyalty tables keep the
 * fork's VARCHAR(19) 'Y-m-d H:i:s' datetime strings — they are compared
 * lexically, never through a datetime cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createLoyaltyPoints();
        $this->createLoyaltyRewards();
        $this->createLoyaltyRedemptions();
        $this->createLoyaltyCodes();
        $this->createPanels();
        $this->createLocalPlans();
        $this->createCustomPlanSettings();
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_plan_settings');
        Schema::dropIfExists('local_plans');
        Schema::dropIfExists('panels');
        Schema::dropIfExists('loyalty_codes');
        Schema::dropIfExists('loyalty_redemptions');
        Schema::dropIfExists('loyalty_rewards');
        Schema::dropIfExists('loyalty_points');
    }

    private function createLoyaltyPoints(): void
    {
        Schema::create('loyalty_points', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id', 64);
            $table->integer('amount');
            $table->integer('remaining')->default(0);
            $table->string('type', 24);
            // Dedup key: one ledger row per (type, ref).
            $table->string('ref', 80)->nullable();
            $table->string('note', 255)->nullable();
            $table->string('expires_at', 19)->nullable();
            $table->string('created_at', 19);

            $table->unique(['type', 'ref']);
            $table->index('chat_id');
        });
    }

    private function createLoyaltyRewards(): void
    {
        Schema::create('loyalty_rewards', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120);
            $table->string('description', 255)->nullable();
            // 'percent' | 'amount' | 'plan'
            $table->string('kind', 16)->default('percent');
            $table->integer('value')->default(0);
            $table->string('plan_id', 64)->nullable();
            $table->string('plan_title', 255)->nullable();
            $table->integer('cost')->default(100);
            $table->integer('stock')->nullable();
            $table->integer('redeemed_count')->default(0);
            $table->integer('per_user_month')->nullable();
            $table->integer('valid_days')->nullable();
            $table->string('starts_at', 19)->nullable();
            $table->string('ends_at', 19)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
        });
    }

    private function createLoyaltyRedemptions(): void
    {
        Schema::create('loyalty_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id', 64);
            $table->integer('reward_id');
            $table->string('reward_title', 120)->nullable();
            $table->integer('points');
            $table->string('code', 32)->unique();
            $table->string('kind', 16);
            $table->integer('value')->default(0);
            $table->string('plan_id', 64)->nullable();
            $table->string('expires_at', 19);
            $table->string('used_at', 19)->nullable();
            $table->string('created_at', 19);

            $table->index('chat_id');
        });
    }

    private function createLoyaltyCodes(): void
    {
        Schema::create('loyalty_codes', function (Blueprint $table): void {
            $table->id();
            $table->integer('reward_id');
            $table->string('code', 120);
            $table->string('assigned_to', 64)->nullable();
            $table->string('assigned_at', 19)->nullable();
            $table->string('created_at', 19);

            $table->unique(['reward_id', 'code']);
        });
    }

    private function createPanels(): void
    {
        Schema::create('panels', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            // 'connectix' | 'marzban' | 'xui' | 'remnawave' | 'hiddify'
            $table->string('type', 20);
            $table->string('base_url', 500);
            $table->string('username', 190)->nullable();
            // Encrypted at the application layer (PanelService::encrypt()).
            $table->text('secret')->nullable();
            $table->text('extra')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createLocalPlans(): void
    {
        Schema::create('local_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->default('');
            $table->bigInteger('price')->default(0);
            $table->integer('days')->default(30);
            $table->decimal('traffic_gb', 10, 2)->default(0);
            $table->integer('devices')->default(1);
            $table->integer('panel_id');
            $table->string('group_name', 50)->default('default');
            $table->boolean('is_free')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createCustomPlanSettings(): void
    {
        Schema::create('custom_plan_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active')->default(true);
            // Custom plan prices are whole-toman rates: DECIMAL(12,0).
            $table->decimal('price_per_gb', 12, 0)->default(5000);
            $table->decimal('price_per_day', 12, 0)->default(1000);
            $table->decimal('min_gb', 10, 2)->default(1);
            $table->decimal('max_gb', 10, 2)->default(1000);
            $table->integer('min_days')->default(1);
            $table->integer('max_days')->default(365);
            $table->integer('default_devices')->default(1);
            $table->timestamp('updated_at')->nullable();
        });
    }
};
