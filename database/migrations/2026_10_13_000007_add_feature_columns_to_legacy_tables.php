<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature-fork additions to the existing legacy tables.
 *
 * Every column the feature fork's services read or write on the legacy
 * tables: receipt review flow, gateway bookkeeping, custom-plan sizing,
 * card-to-card source tracking, wallet adjustment notes, referral
 * attribution, block flag, and multi-device count. Each column is guarded
 * individually with hasColumn() so an already-populated production database
 * is only widened, never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->extendPayments();
        $this->extendWalletTransactions();
        $this->extendUsers();
        $this->extendClients();
    }

    public function down(): void
    {
        $this->dropColumns('payments', [
            'receipt_image', 'transaction_id', 'approved_by', 'approved_at',
            'delivered_service_info', 'currency', 'gateway_data', 'src_card',
        ]);
        $this->dropColumns('wallet_transactions', ['description', 'src_card']);
        $this->dropColumns('users', ['referred_by', 'is_blocked']);
        $this->dropColumns('clients', ['count_of_devices', 'is_deleted']);
    }

    private function extendPayments(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        if (! Schema::hasColumn('payments', 'currency')) {
            Schema::table('payments', function (Blueprint $table): void {
                // Toman label kept as its own column for display parity.
                $table->string('currency', 32)->default('تومان')->after('price');
            });
        }

        if (! Schema::hasColumn('payments', 'receipt_image')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->text('receipt_image')->nullable();
            });
        }

        if (! Schema::hasColumn('payments', 'transaction_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->string('transaction_id')->nullable();
            });
        }

        if (! Schema::hasColumn('payments', 'approved_by')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->string('approved_by')->nullable();
            });
        }

        if (! Schema::hasColumn('payments', 'approved_at')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->timestamp('approved_at')->nullable();
            });
        }

        if (! Schema::hasColumn('payments', 'delivered_service_info')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->text('delivered_service_info')->nullable();
            });
        }

        if (! Schema::hasColumn('payments', 'gateway_data')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->text('gateway_data')->nullable();
            });
        }

        if (! Schema::hasColumn('payments', 'src_card')) {
            Schema::table('payments', function (Blueprint $table): void {
                // The card number the customer paid from (16 digits).
                $table->string('src_card', 16)->nullable();
            });
        }
    }

    private function extendWalletTransactions(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return;
        }

        if (! Schema::hasColumn('wallet_transactions', 'description')) {
            Schema::table('wallet_transactions', function (Blueprint $table): void {
                // Optional admin note shown in the wallet history and announced to the user.
                $table->text('description')->nullable();
            });
        }

        if (! Schema::hasColumn('wallet_transactions', 'src_card')) {
            Schema::table('wallet_transactions', function (Blueprint $table): void {
                $table->string('src_card', 16)->nullable();
            });
        }
    }

    private function extendUsers(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'referred_by')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->integer('referred_by')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'is_blocked')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('is_blocked')->default(false);
            });
        }
    }

    private function extendClients(): void
    {
        if (! Schema::hasTable('clients')) {
            return;
        }

        if (! Schema::hasColumn('clients', 'count_of_devices')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->integer('count_of_devices')->default(1);
            });
        }

        if (! Schema::hasColumn('clients', 'is_deleted')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->boolean('is_deleted')->default(false);
            });
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column)
        ));

        if ($existing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($existing): void {
            $table->dropColumn($existing);
        });
    }
};
