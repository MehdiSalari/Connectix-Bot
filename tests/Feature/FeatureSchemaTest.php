<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\BroadcastLog;
use App\Models\BroadcastRecipient;
use App\Models\Client;
use App\Models\CouponRestriction;
use App\Models\GatewayOrder;
use App\Models\LocalPlan;
use App\Models\LoyaltyPoint;
use App\Models\Panel;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\ResellerCommission;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\WinbackSent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema coverage for the feature-fork migrations.
 *
 * Asserts every feature-fork table exists after a full migrate, that the
 * columns added to the legacy tables landed, that the natural keys the fork
 * relies on (composite primary key, dedup uniques) are enforced, and that
 * the existing Payment model still behaves exactly as before the extension.
 */
class FeatureSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_a_sales_finance_tables_exist(): void
    {
        foreach ([
            'resellers', 'reseller_requests', 'reseller_referrals',
            'reseller_commissions', 'reseller_payouts', 'payment_cards',
            'flash_sales', 'transactions_audit_log',
        ] as $table) {
            $this->assertHasTable($table);
        }
    }

    public function test_group_b_support_admin_tables_exist(): void
    {
        foreach (['tickets', 'ticket_messages', 'login_attempts', 'admin_activity'] as $table) {
            $this->assertHasTable($table);
        }

        foreach (['is_primary', 'is_active', 'permissions', 'telegram_chat_id', 'two_factor_secret'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('admins', $column),
                "admins.{$column} is missing."
            );
        }
    }

    public function test_group_c_bot_ui_tables_exist(): void
    {
        foreach ([
            'bot_buttons', 'bot_emojis', 'emoji_packs_imported',
            'broadcast_logs', 'broadcast_recipients', 'scheduled_broadcasts',
        ] as $table) {
            $this->assertHasTable($table);
        }
    }

    public function test_group_d_gateway_crypto_tables_exist(): void
    {
        foreach (['payment_gateways', 'gateway_orders', 'crypto_payments', 'crypto_hashes'] as $table) {
            $this->assertHasTable($table);
        }
    }

    public function test_group_e_loyalty_panel_tables_exist(): void
    {
        foreach ([
            'loyalty_points', 'loyalty_rewards', 'loyalty_redemptions',
            'loyalty_codes', 'panels', 'local_plans', 'custom_plan_settings',
        ] as $table) {
            $this->assertHasTable($table);
        }
    }

    public function test_group_f_notification_lifecycle_tables_exist(): void
    {
        foreach ([
            'notification_settings', 'plan_notifications_sent',
            'winback_settings', 'winback_logs', 'winback_sent',
            'welcome_credit_log', 'coupon_restrictions',
        ] as $table) {
            $this->assertHasTable($table);
        }
    }

    public function test_payments_feature_columns_exist_and_accept_writes(): void
    {
        foreach ([
            'receipt_image', 'transaction_id', 'approved_by', 'approved_at',
            'delivered_service_info', 'currency', 'gateway_data', 'src_card',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('payments', $column),
                "payments.{$column} is missing."
            );
        }

        $payment = $this->makePayment();

        $payment->update([
            'receipt_image' => 'receipts/r1.png',
            'transaction_id' => 'TRX-1',
            'approved_by' => 'admin@acme.test',
            'approved_at' => now(),
            'delivered_service_info' => 'account details',
            'currency' => 'تومان',
            'gateway_data' => '{"ref":"x"}',
            'src_card' => '6037991111111111',
        ]);

        $payment->refresh();

        $this->assertSame('TRX-1', $payment->transaction_id);
        $this->assertSame('admin@acme.test', $payment->approved_by);
        $this->assertSame('6037991111111111', $payment->src_card);
    }

    public function test_wallet_transactions_feature_columns_exist(): void
    {
        foreach (['description', 'src_card'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('wallet_transactions', $column),
                "wallet_transactions.{$column} is missing."
            );
        }
    }

    public function test_users_and_clients_feature_columns_exist(): void
    {
        foreach (['referred_by', 'is_blocked'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('users', $column),
                "users.{$column} is missing."
            );
        }

        foreach (['count_of_devices', 'is_deleted'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('clients', $column),
                "clients.{$column} is missing."
            );
        }

        $user = User::query()->create(['chat_id' => '7001']);

        // Laravel's in-memory instance after create() only carries the
        // attributes that were passed; the column defaults live in the DDL
        // and show up on re-fetch. Tinyint(1) comes back as an integer on
        // sqlite, so assert the stored 0/1.
        $this->assertSame(0, (int) $user->fresh()->is_blocked);
        $this->assertNull($user->fresh()->referred_by);

        $client = Client::query()->create(['id' => 'client-uuid-1', 'chat_id' => '7001']);

        $this->assertSame(0, (int) $client->fresh()->is_deleted);
    }

    public function test_admins_extended_columns_have_defaults(): void
    {
        $admin = Admin::query()->create([
            'email' => 'schema-'.uniqid().'@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token',
            'chat_id' => '55',
        ]);

        // Tinyint(1) columns come back as SQLite integers; assert the stored
        // 0/1 rather than PHP strict boolean identity.
        $this->assertSame(0, (int) $admin->fresh()->is_primary);
        $this->assertSame(1, (int) $admin->fresh()->is_active);
        $this->assertNull($admin->permissions);
        $this->assertNull($admin->two_factor_secret);
    }

    public function test_payment_model_is_unaffected_by_schema_extension(): void
    {
        $paid = $this->makePayment(['is_paid' => '1']);

        $this->assertSame('1', $paid->getRawOriginal('is_paid'));
        $this->assertSame(PaymentStatus::Paid, $paid->is_paid);
        $this->assertFalse($paid->isPending());
        $this->assertSame(159000, $paid->priceAmount());

        $pending = $this->makePayment(['is_paid' => PaymentStatus::Pending]);

        $this->assertNull($pending->getRawOriginal('is_paid'));
        $this->assertSame(PaymentStatus::Pending, $pending->is_paid);
        $this->assertTrue($pending->isPending());
    }

    public function test_broadcast_recipients_use_a_composite_primary_key(): void
    {
        BroadcastRecipient::query()->create(['broadcast_id' => 1, 'seq' => 1, 'chat_id' => '100']);
        BroadcastRecipient::query()->create(['broadcast_id' => 1, 'seq' => 2, 'chat_id' => '200']);

        try {
            BroadcastRecipient::query()->create(['broadcast_id' => 1, 'seq' => 1, 'chat_id' => '999']);
            $this->fail('A duplicate (broadcast_id, seq) pair must violate the composite primary key.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        // Same seq on a different broadcast is fine: the key is composite.
        BroadcastRecipient::query()->create(['broadcast_id' => 2, 'seq' => 1, 'chat_id' => '300']);

        $this->assertSame(3, BroadcastRecipient::query()->count());
    }

    public function test_natural_unique_keys_are_enforced(): void
    {
        LoyaltyPoint::query()->create([
            'chat_id' => '100', 'amount' => 50, 'type' => 'adjustment',
            'ref' => '42', 'created_at' => '2026-10-13 10:00:00',
        ]);

        try {
            LoyaltyPoint::query()->create([
                'chat_id' => '200', 'amount' => 10, 'type' => 'adjustment',
                'ref' => '42', 'created_at' => '2026-10-13 10:00:00',
            ]);
            $this->fail('The (type, ref) dedup key must be enforced.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        WinbackSent::query()->create(['chat_id' => '100', 'cycle_key' => '2026-10-01']);

        try {
            WinbackSent::query()->create(['chat_id' => '100', 'cycle_key' => '2026-10-01']);
            $this->fail('The (chat_id, cycle_key) dedup key must be enforced.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        CouponRestriction::query()->create(['coupon_code' => 'WELCOME10', 'usage_limit' => 5]);

        $restriction = CouponRestriction::query()->find('WELCOME10');

        $this->assertNotNull($restriction);
        $this->assertSame(5, $restriction->usage_limit);
    }

    public function test_new_models_persist_and_relate(): void
    {
        $reseller = Reseller::query()->create([
            'chat_id' => '8001',
            'ref_code' => 'REF1',
            'commission_rate' => 15,
            'status' => 'ACTIVE',
        ]);

        ResellerCommission::query()->create([
            'reseller_id' => $reseller->id,
            'order_amount' => 1000,
            'commission_rate' => 15,
            'commission_amount' => 150,
        ]);

        $this->assertSame(150.0, (float) $reseller->commissions()->first()->commission_amount);
        $this->assertTrue($reseller->isActive());

        $ticket = Ticket::query()->create([
            'ticket_number' => 'T-1',
            'chat_id' => '8001',
            'subject' => 'cannot connect',
        ]);

        TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'user',
            'message' => 'help',
        ]);

        $this->assertSame('help', $ticket->messages()->first()->message);
        $this->assertSame($ticket->id, TicketMessage::query()->first()->ticket->id);

        $panel = Panel::query()->create([
            'name' => 'main',
            'type' => 'connectix',
            'base_url' => 'https://panel.example',
        ]);

        LocalPlan::query()->create([
            'name' => '1 month',
            'price' => 250000,
            'panel_id' => $panel->id,
        ]);

        $this->assertSame('main', LocalPlan::query()->first()->panel->name);

        BroadcastLog::query()->create(['type' => 'SEND']);

        GatewayOrder::query()->create([
            'token' => 'tok-1',
            'chat_id' => '8001',
            'gateway' => 'zarinpal',
            'amount_toman' => 250000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('tok-1', GatewayOrder::query()->first()->token);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePayment(array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'order_number' => 'F'.substr(uniqid(), -8),
            'chat_id' => '777000',
            'price' => '159,000',
        ], $overrides));
    }

    private function assertHasTable(string $table): void
    {
        $this->assertTrue(
            Schema::hasTable($table),
            "Table {$table} does not exist after migrate."
        );
    }
}
