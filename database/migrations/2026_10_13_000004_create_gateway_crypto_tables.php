<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature-fork payment gateway and crypto schema (group D).
 *
 * Mirrors the online-gateway configuration (ZarinPal / NextPay /
 * NOWPayments), the per-order gateway ledger, and the TRX/TON crypto
 * payment flow the feature fork verifies on-chain. Toman amounts are
 * BIGINT; the unique index on (network, tx_hash) encodes the fork's
 * "one on-chain transfer can pay only one order" rule, and the
 * (network, hash) uniqueness on crypto_hashes remembers every submitted
 * hash forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createPaymentGateways();
        $this->createGatewayOrders();
        $this->createCryptoPayments();
        $this->createCryptoHashes();
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_hashes');
        Schema::dropIfExists('crypto_payments');
        Schema::dropIfExists('gateway_orders');
        Schema::dropIfExists('payment_gateways');
    }

    private function createPaymentGateways(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table): void {
            $table->id();
            $table->string('gateway_name', 64)->unique();
            $table->string('title')->nullable();
            $table->boolean('is_active')->default(false);
            $table->text('api_key')->nullable();
            $table->string('merchant_id')->nullable();
            $table->text('secret_key')->nullable();
            $table->boolean('sandbox')->default(true);
            $table->string('currency')->default('IRT');
            $table->decimal('min_amount', 15, 2)->default(0);
            $table->integer('sort_order')->default(0);
            // Gateway-specific settings blob, e.g. callback URL and extra keys.
            $table->text('config_json')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    private function createGatewayOrders(): void
    {
        Schema::create('gateway_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('chat_id');
            // 'plan' | 'wallet' — what the order pays for.
            $table->string('kind', 16)->default('plan');
            $table->integer('payment_id')->nullable();
            $table->integer('wallet_tx_id')->nullable();
            $table->string('gateway', 32);
            $table->bigInteger('amount_toman')->default(0);
            $table->bigInteger('amount_sent')->default(0);
            $table->string('usd_amount', 32)->nullable();
            $table->string('authority', 128)->nullable();
            $table->string('ref_id', 128)->nullable();
            $table->text('pay_url')->nullable();
            $table->string('status', 16)->default('PENDING');
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('paid_at')->nullable();

            $table->index(['chat_id', 'status']);
        });
    }

    private function createCryptoPayments(): void
    {
        Schema::create('crypto_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id');
            $table->string('kind', 16)->default('plan');
            $table->integer('payment_id')->nullable();
            $table->integer('wallet_tx_id')->nullable();
            $table->string('network', 8);
            $table->text('pay_address');
            $table->bigInteger('amount_toman')->default(0);
            $table->string('rate_toman', 64)->nullable();
            $table->bigInteger('expected_units')->default(0);
            $table->bigInteger('received_units')->default(0);
            $table->text('submitted_hash')->nullable();
            $table->text('tx_hash')->nullable();
            $table->text('sender_address')->nullable();
            // 'WAITING' | 'CONFIRMED' | 'REJECTED' | ...
            $table->string('status', 24)->default('WAITING');
            $table->text('review_reason')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_check_at')->nullable();

            // One on-chain transfer pays one order; NULLs may repeat.
            $table->unique(['network', 'tx_hash']);
            $table->index('chat_id');
            $table->index('status');
        });
    }

    private function createCryptoHashes(): void
    {
        Schema::create('crypto_hashes', function (Blueprint $table): void {
            $table->id();
            $table->string('network', 8);
            $table->text('hash');
            $table->integer('order_id')->nullable();
            $table->string('chat_id')->nullable();
            $table->timestamp('created_at')->nullable();

            // A hash can be submitted only once, whatever the outcome.
            $table->unique(['network', 'hash']);
        });
    }
};
