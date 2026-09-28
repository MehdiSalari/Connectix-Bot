<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy Connectix Bot schema.
 *
 * Every migration in this file mirrors the exact column types, nullability and
 * keys of the production MySQL schema shipped with the legacy application
 * (see debug/echovpn.sql). Compatibility with the existing production database
 * takes priority over schema aesthetics, so a few columns keep their original
 * VARCHAR storage:
 *
 *  - payments.price              formatted string, e.g. "159,000"
 *  - payments.is_paid            nullable tri-state ('' pending, 0, 1)
 *  - wallets.balance             string, manipulated with (int) casts
 *  - wallet_transactions.amount  string
 *  - clients.id                  external UUID from the Connectix panel
 *
 * Migrations are guarded with hasTable()/hasColumn() so they can be run against
 * an already populated production database without destroying data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createAdmins();
        $this->createUsers();
        $this->createWallets();
        $this->createClients();
        $this->createPayments();
        $this->createWalletTransactions();
        $this->createSmsPayments();
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_payments');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('users');
        Schema::dropIfExists('admins');
    }

    private function createAdmins(): void
    {
        if (Schema::hasTable('admins')) {
            return;
        }

        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 190)->unique();
            $table->string('password');
            $table->string('token');
            $table->string('chat_id');
            $table->enum('role', ['admin', 'editor'])->default('admin');
        });
    }

    private function createUsers(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id')->nullable()->unique();
            $table->string('telegram_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('avatar')->nullable();
            $table->longText('action')->nullable();
            $table->boolean('test')->default(false);
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createWallets(): void
    {
        if (Schema::hasTable('wallets')) {
            return;
        }

        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id')->nullable()->unique();
            $table->string('balance')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createClients(): void
    {
        if (Schema::hasTable('clients')) {
            return;
        }

        Schema::create('clients', function (Blueprint $table): void {
            $table->string('id', 100)->primary();
            $table->integer('count_of_devices')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('chat_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createPayments(): void
    {
        if (Schema::hasTable('payments')) {
            return;
        }

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 20)->nullable()->unique();
            $table->string('chat_id')->nullable();
            $table->string('client_id')->nullable();
            $table->string('plan_id')->nullable();
            $table->string('price')->nullable();
            $table->string('coupon')->nullable();
            $table->string('is_paid', 50)->nullable();
            $table->string('method')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createWalletTransactions(): void
    {
        if (Schema::hasTable('wallet_transactions')) {
            return;
        }

        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->integer('wallet_id')->nullable();
            $table->string('amount')->nullable();
            $table->string('operation')->nullable();
            $table->string('chat_id')->nullable();
            $table->string('status')->nullable();
            $table->string('type')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createSmsPayments(): void
    {
        if (Schema::hasTable('sms_payments')) {
            return;
        }

        Schema::create('sms_payments', function (Blueprint $table): void {
            $table->id();
            $table->text('message');
            $table->integer('amount')->default(0);
            $table->string('bank')->nullable();
            $table->string('payment_id')->nullable();
            $table->string('payment_type')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }
};
