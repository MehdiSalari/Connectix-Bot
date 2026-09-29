<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AdminRole;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WalletOperation;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Payment;
use App\Models\SmsPayment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The helpers every service layer call rests on.
 *
 * These are the small model contracts that legacy PHP scattered across
 * `paycheck()`, `smsPayment('check')` and the wallet arithmetic: the tri-state
 * `is_paid` column, the string balance, the legacy matching window for an
 * inbound bank SMS, and the relations keyed by chat_id rather than by the
 * local primary key. A regression here is invisible until a purchase or a
 * deposit goes live, so each one is pinned directly.
 */
class ModelHelpersTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Payment.is_paid: the tri-state attribute
    // -----------------------------------------------------------------

    public function test_a_null_column_reads_as_pending_and_is_pending(): void
    {
        $payment = Payment::query()->create([
            'order_number' => 'CX-TEST-1',
            'chat_id' => '555',
            'client_id' => 'new',
            'plan_id' => '11',
            'price' => '120,000',
            'is_paid' => null,
            'method' => PaymentMethod::Card,
        ]);

        $this->assertSame(PaymentStatus::Pending, $payment->is_paid);
        $this->assertTrue($payment->isPending());
    }

    public function test_the_legacy_raw_values_read_as_their_status(): void
    {
        $this->assertSame(PaymentStatus::Paid, Payment::query()->create([
            'order_number' => 'CX-TEST-2', 'chat_id' => '1', 'is_paid' => '1', 'method' => 'card',
        ])->is_paid);

        $this->assertSame(PaymentStatus::Rejected, Payment::query()->create([
            'order_number' => 'CX-TEST-3', 'chat_id' => '1', 'is_paid' => '0', 'method' => 'card',
        ])->is_paid);
    }

    public function test_writing_pending_stores_a_real_null_so_legacy_rows_stay_untouched(): void
    {
        $payment = Payment::query()->create([
            'order_number' => 'CX-TEST-4',
            'chat_id' => '1',
            'is_paid' => PaymentStatus::Pending,
            'method' => 'card',
        ]);

        $this->assertNull(DB::table('payments')->where('id', $payment->id)->value('is_paid'));

        $payment->is_paid = PaymentStatus::Paid;
        $payment->save();

        $this->assertSame('1', DB::table('payments')->where('id', $payment->id)->value('is_paid'));
    }

    public function test_a_decided_order_is_no_longer_pending(): void
    {
        $payment = Payment::query()->create([
            'order_number' => 'CX-TEST-5', 'chat_id' => '1', 'is_paid' => '1', 'method' => 'card',
        ]);

        $this->assertFalse($payment->isPending());
    }

    // -----------------------------------------------------------------
    // Payment helpers
    // -----------------------------------------------------------------

    public function test_a_new_client_order_is_recognised_by_the_placeholder(): void
    {
        $fresh = Payment::query()->create([
            'order_number' => 'CX-TEST-6', 'chat_id' => '1', 'client_id' => 'new', 'method' => 'card',
        ]);
        $renewal = Payment::query()->create([
            'order_number' => 'CX-TEST-7', 'chat_id' => '1', 'client_id' => 'panel-uuid', 'method' => 'card',
        ]);

        $this->assertTrue($fresh->isNewClient());
        $this->assertFalse($renewal->isNewClient());
    }

    public function test_the_price_is_read_as_a_number_without_the_separators(): void
    {
        $payment = Payment::query()->create([
            'order_number' => 'CX-TEST-8', 'chat_id' => '1', 'price' => '1,250,000', 'method' => 'card',
        ]);

        $this->assertSame(1250000, $payment->priceAmount());
    }

    // -----------------------------------------------------------------
    // Admin / User flags
    // -----------------------------------------------------------------

    public function test_only_the_admin_role_passes_the_admin_check(): void
    {
        $admin = Admin::query()->create([
            'email' => 'a@b.test', 'password' => 'x', 'token' => 't', 'chat_id' => '1', 'role' => AdminRole::Admin,
        ]);
        $editor = Admin::query()->create([
            'email' => 'c@d.test', 'password' => 'x', 'token' => 't2', 'chat_id' => '2', 'role' => AdminRole::Editor,
        ]);

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($editor->isAdmin());
    }

    public function test_admin_credentials_and_tokens_are_hidden_from_serialisation(): void
    {
        $admin = Admin::query()->create([
            'email' => 'a@b.test', 'password' => 'secret-pass', 'token' => 'seller-token', 'chat_id' => '1',
        ]);

        $array = $admin->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('token', $array);
    }

    public function test_the_trial_flag_reports_whether_the_free_test_was_used(): void
    {
        $used = User::query()->create(['chat_id' => '10', 'test' => true]);
        $fresh = User::query()->create(['chat_id' => '11', 'test' => false]);

        $this->assertTrue($used->hasUsedTest());
        $this->assertFalse($fresh->hasUsedTest());
    }

    public function test_the_test_flag_is_stored_as_a_real_boolean(): void
    {
        $user = User::query()->create(['chat_id' => '12', 'test' => 1]);
        $this->assertTrue($user->test);
        $this->assertSame(1, DB::table('users')->where('id', $user->id)->value('test'));

        $user->test = false;
        $user->save();
        $this->assertSame(0, DB::table('users')->where('id', $user->id)->value('test'));
    }

    // -----------------------------------------------------------------
    // Chat-keyed relations
    // -----------------------------------------------------------------

    public function test_clients_by_chat_id_finds_accounts_whose_local_back_reference_is_missing(): void
    {
        $user = User::query()->create(['chat_id' => '77']);

        Client::query()->create(['id' => 'uuid-linked', 'user_id' => $user->id, 'chat_id' => '77']);
        Client::query()->create(['id' => 'uuid-orphan', 'user_id' => null, 'chat_id' => '77']);
        Client::query()->create(['id' => 'uuid-other', 'user_id' => null, 'chat_id' => '88']);

        $byId = $user->clients()->pluck('id')->all();
        $byChat = $user->clientsByChatId()->pluck('id')->all();

        $this->assertSame(['uuid-linked'], $byId);
        $this->assertEqualsCanonicalizing(['uuid-linked', 'uuid-orphan'], $byChat);
    }

    public function test_a_client_keeps_its_external_uuid_as_the_primary_key(): void
    {
        $client = Client::query()->create(['id' => 'ext-uuid-1', 'chat_id' => '77']);

        $this->assertFalse((new Client)->getIncrementing());
        $this->assertSame('ext-uuid-1', $client->getKey());
        $this->assertTrue($client->exists);
    }

    // -----------------------------------------------------------------
    // Wallet arithmetic on legacy string columns
    // -----------------------------------------------------------------

    public function test_the_balance_is_always_an_integer_even_from_the_string_column(): void
    {
        $wallet = Wallet::query()->create(['chat_id' => '5', 'balance' => '125000']);

        $this->assertSame(125000, $wallet->balanceAmount());

        $wallet->balance = null;
        $this->assertSame(0, $wallet->balanceAmount());
    }

    public function test_a_ledger_entry_casts_its_enums(): void
    {
        $wallet = Wallet::query()->create(['chat_id' => '5', 'balance' => '0']);

        $transaction = WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'amount' => '50000',
            'operation' => WalletOperation::Increase,
            'chat_id' => '5',
            'status' => WalletTransactionStatus::Pending,
            'type' => WalletTransactionType::CardToCard,
        ]);

        $this->assertSame(WalletOperation::Increase, $transaction->operation);
        $this->assertSame(WalletTransactionStatus::Pending, $transaction->status);
        $this->assertSame(WalletTransactionType::CardToCard, $transaction->type);
        $this->assertSame(50000, $transaction->amount);
    }

    // -----------------------------------------------------------------
    // The legacy SMS matching window
    // -----------------------------------------------------------------

    public function test_an_available_deposit_is_unmatched_unexpired_and_already_created(): void
    {
        SmsPayment::query()->create([
            'message' => 'deposit 50000', 'amount' => 50000, 'bank' => 'mellat',
            'expired_at' => now()->addMinutes(5), 'created_at' => now()->subMinute(),
        ]);

        $this->assertSame(1, SmsPayment::query()->available()->count());
    }

    public function test_the_available_scope_skips_matched_expired_and_future_deposits(): void
    {
        SmsPayment::query()->create([
            'message' => 'matched', 'amount' => 1000, 'payment_id' => 'CX-1',
            'expired_at' => now()->addMinutes(5), 'created_at' => now()->subMinute(),
        ]);
        SmsPayment::query()->create([
            'message' => 'expired', 'amount' => 2000,
            'expired_at' => now()->subMinute(), 'created_at' => now()->subHour(),
        ]);
        SmsPayment::query()->create([
            'message' => 'future', 'amount' => 3000,
            'expired_at' => now()->addMinutes(5), 'created_at' => now()->addMinute(),
        ]);

        $this->assertSame(0, SmsPayment::query()->available()->count());
    }
}
