<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature-fork sales and finance schema (group A).
 *
 * Mirrors the tables the feature fork created ad hoc from its services
 * (reseller/affiliate system, card tracking, flash sales, audit log).
 * Money columns keep the fork's DECIMAL precision: reseller money is
 * DECIMAL(15,2). Defaults follow the fork where the column carries a
 * business default ('PENDING' statuses, zero balances).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createResellers();
        $this->createResellerRequests();
        $this->createResellerReferrals();
        $this->createResellerCommissions();
        $this->createResellerPayouts();
        $this->createPaymentCards();
        $this->createFlashSales();
        $this->createTransactionsAuditLog();
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions_audit_log');
        Schema::dropIfExists('flash_sales');
        Schema::dropIfExists('payment_cards');
        Schema::dropIfExists('reseller_payouts');
        Schema::dropIfExists('reseller_commissions');
        Schema::dropIfExists('reseller_referrals');
        Schema::dropIfExists('reseller_requests');
        Schema::dropIfExists('resellers');
    }

    private function createResellers(): void
    {
        Schema::create('resellers', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->string('chat_id')->unique();
            $table->string('ref_code', 64)->unique();
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('total_earned', 15, 2)->default(0);
            $table->string('status')->default('PENDING');
            $table->string('card_number', 50)->nullable();
            $table->string('card_name')->nullable();
            $table->string('sheba_number', 50)->nullable();
            $table->integer('test_limit')->nullable();
            $table->integer('test_used')->default(0);
            // 'total' | 'monthly' — how the reseller's test-account quota resets.
            $table->string('test_period_mode')->nullable();
            $table->string('test_period_key')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function createResellerRequests(): void
    {
        Schema::create('reseller_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id');
            $table->integer('user_id')->nullable();
            $table->decimal('wallet_balance_at_request', 15, 2)->default(0);
            $table->string('status')->default('PENDING');
            $table->text('admin_note')->nullable();
            $table->string('processed_by')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function createResellerReferrals(): void
    {
        Schema::create('reseller_referrals', function (Blueprint $table): void {
            $table->id();
            $table->integer('reseller_id');
            $table->string('referred_chat_id')->unique();
            $table->integer('referred_user_id')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createResellerCommissions(): void
    {
        Schema::create('reseller_commissions', function (Blueprint $table): void {
            $table->id();
            $table->integer('reseller_id');
            $table->integer('payment_id')->nullable();
            $table->string('order_number', 64)->nullable();
            $table->decimal('order_amount', 15, 2)->default(0);
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('commission_amount', 15, 2)->default(0);
            $table->string('currency')->default('IRT');
            $table->string('status')->default('PENDING');
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createResellerPayouts(): void
    {
        Schema::create('reseller_payouts', function (Blueprint $table): void {
            $table->id();
            $table->integer('reseller_id');
            $table->decimal('amount', 15, 2);
            $table->string('currency')->default('IRT');
            $table->string('card_number', 50)->nullable();
            $table->string('card_name')->nullable();
            $table->string('sheba_number', 50)->nullable();
            $table->string('status')->default('PENDING');
            $table->string('tracking_code', 128)->nullable();
            $table->text('admin_note')->nullable();
            $table->string('processed_by')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function createPaymentCards(): void
    {
        Schema::create('payment_cards', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id', 64);
            $table->string('card', 16);
            $table->string('source', 20)->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createFlashSales(): void
    {
        Schema::create('flash_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('plan_id', 64);
            $table->string('plan_title')->nullable();
            // 'percent' | 'amount'
            $table->string('discount_type', 10)->default('percent');
            $table->integer('discount_value')->default(0);
            // Tehran-local 'Y-m-d H:i:s' strings, compared lexically.
            $table->string('starts_at', 19);
            $table->string('ends_at', 19);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createTransactionsAuditLog(): void
    {
        Schema::create('transactions_audit_log', function (Blueprint $table): void {
            $table->id();
            $table->integer('payment_id')->nullable();
            $table->string('order_number', 64)->nullable();
            $table->string('action', 64);
            $table->string('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('previous_status', 64)->nullable();
            $table->string('new_status', 64)->nullable();
            $table->text('details')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }
};
