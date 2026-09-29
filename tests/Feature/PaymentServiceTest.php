<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Order creation, the daily order number sequence, the already-decided guard
 * and the admin search, ported from `savePayment()`, `paycheck()` and the
 * payment search query.
 */
class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = new PaymentService;
    }

    private function makeUser(string $chatId = '555', string $name = 'Ali'): User
    {
        return User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => $chatId,
            'name' => $name,
        ]);
    }

    public function test_it_creates_an_order_with_a_pending_status_and_an_order_number(): void
    {
        $payment = $this->payments->create(
            chatId: '555',
            clientId: Payment::NEW_CLIENT,
            planId: '42',
            price: '120,000',
            method: PaymentMethod::Card,
        );

        $this->assertNotNull($payment);
        $this->assertSame('555', $payment->chat_id);
        $this->assertSame(Payment::NEW_CLIENT, $payment->client_id);
        $this->assertSame('42', $payment->plan_id);
        $this->assertSame('120,000', $payment->price);
        $this->assertSame(120000, $payment->priceAmount());
        $this->assertSame(PaymentMethod::Card, $payment->method);
        $this->assertSame(PaymentStatus::Pending, $payment->is_paid);
        $this->assertTrue($payment->isPending());
        $this->assertTrue($payment->isNewClient());
    }

    public function test_the_order_number_starts_with_the_day_prefix(): void
    {
        $payment = $this->payments->create('555', Payment::NEW_CLIENT, '42', '1000', PaymentMethod::Card);

        $expected = sprintf('CX%s%s%s01', date('y'), date('m'), date('d'));

        $this->assertSame($expected, $payment?->order_number);
    }

    public function test_the_daily_sequence_increments_and_is_padded(): void
    {
        $prefix = sprintf('CX%s%s%s', date('y'), date('m'), date('d'));

        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $numbers[] = $this->payments->create('555', Payment::NEW_CLIENT, '42', '1000', PaymentMethod::Card)?->order_number;
        }

        $this->assertSame([$prefix.'01', $prefix.'02', $prefix.'03'], $numbers);
    }

    public function test_the_sequence_continues_across_different_chats(): void
    {
        $prefix = sprintf('CX%s%s%s', date('y'), date('m'), date('d'));

        $this->makeUser('555', 'Ali');
        $this->makeUser('777', 'Sara');

        $first = $this->payments->create('555', Payment::NEW_CLIENT, '42', '1000', PaymentMethod::Card);
        $second = $this->payments->create('777', Payment::NEW_CLIENT, '42', '1000', PaymentMethod::Card);

        $this->assertSame($prefix.'01', $first?->order_number);
        $this->assertSame($prefix.'02', $second?->order_number);
    }

    public function test_the_sequence_beyond_ninety_nine_is_not_truncated(): void
    {
        $prefix = sprintf('CX%s%s%s', date('y'), date('m'), date('d'));

        // 99 existing orders today, so the next one is the hundredth.
        $rows = [];

        for ($i = 1; $i <= 99; $i++) {
            $rows[] = [
                'chat_id' => '555',
                'client_id' => Payment::NEW_CLIENT,
                'plan_id' => '1',
                'price' => '1000',
                'is_paid' => null,
                'method' => PaymentMethod::Card,
                'created_at' => now(),
                'order_number' => $prefix.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ];
        }

        Payment::query()->insert($rows);

        $payment = $this->payments->create('555', Payment::NEW_CLIENT, '1', '1000', PaymentMethod::Card);

        $this->assertSame($prefix.'100', $payment?->order_number);
    }

    public function test_a_wallet_order_is_created_as_already_paid(): void
    {
        $payment = $this->payments->create(
            chatId: '555',
            clientId: Payment::NEW_CLIENT,
            planId: '42',
            price: 5000,
            method: PaymentMethod::Wallet,
            status: PaymentStatus::Paid,
        );

        $this->assertSame(PaymentStatus::Paid, $payment?->is_paid);
        $this->assertTrue($this->payments->isDecided($payment));
    }

    public function test_the_coupon_code_is_stored(): void
    {
        $payment = $this->payments->create(
            chatId: '555',
            clientId: Payment::NEW_CLIENT,
            planId: '42',
            price: '90000',
            method: PaymentMethod::Card,
            coupon: 'SAVE10',
        );

        $this->assertSame('SAVE10', $payment?->coupon);
    }

    public function test_it_marks_a_pending_order_as_paid(): void
    {
        $payment = $this->payments->create('555', Payment::NEW_CLIENT, '42', '1000', PaymentMethod::Card);

        $this->assertTrue($this->payments->markPaid((int) $payment?->id));
        $this->assertSame(PaymentStatus::Paid, $this->payments->find((int) $payment?->id)?->is_paid);
    }

    public function test_it_marks_a_pending_order_as_rejected(): void
    {
        $payment = $this->payments->create('555', Payment::NEW_CLIENT, '42', '1000', PaymentMethod::Card);

        $this->assertTrue($this->payments->markRejected((int) $payment?->id));
        $this->assertSame(PaymentStatus::Rejected, $this->payments->find((int) $payment?->id)?->is_paid);
    }

    public function test_a_paid_order_cannot_be_rejected_afterwards(): void
    {
        $payment = $this->payments->create('555', Payment::NEW_CLIENT, '42', '1000', PaymentMethod::Card);

        $this->payments->markPaid((int) $payment?->id);

        // Legacy showed the current status and stopped on a second tap.
        $this->assertFalse($this->payments->markRejected((int) $payment?->id));
        $this->assertSame(PaymentStatus::Paid, $this->payments->find((int) $payment?->id)?->is_paid);
    }

    public function test_a_rejected_order_cannot_be_paid_afterwards(): void
    {
        $payment = $this->payments->create('555', Payment::NEW_CLIENT, '42', '1000', PaymentMethod::Card);

        $this->payments->markRejected((int) $payment?->id);

        $this->assertFalse($this->payments->markPaid((int) $payment?->id));
        $this->assertSame(PaymentStatus::Rejected, $this->payments->find((int) $payment?->id)?->is_paid);
    }

    public function test_changing_the_status_of_a_missing_payment_returns_false(): void
    {
        $this->assertFalse($this->payments->markPaid(999999));
    }

    public function test_it_attaches_the_real_client_id_to_a_new_account_order(): void
    {
        $payment = $this->payments->create('555', Payment::NEW_CLIENT, '42', '1000', PaymentMethod::Card);

        $this->assertTrue($this->payments->attachClientId((int) $payment?->id, 'client-uuid-1'));

        $refreshed = $this->payments->find((int) $payment?->id);

        $this->assertSame('client-uuid-1', $refreshed?->client_id);
        $this->assertFalse($refreshed->isNewClient());
    }

    public function test_it_finds_an_order_by_its_order_number(): void
    {
        $payment = $this->payments->create('555', Payment::NEW_CLIENT, '42', '1000', PaymentMethod::Card);

        $found = $this->payments->findByOrderNumber((string) $payment?->order_number);

        $this->assertSame($payment?->id, $found?->id);
    }

    public function test_it_lists_a_chats_orders_newest_first(): void
    {
        $this->makeUser('555');
        $this->makeUser('777');

        // Explicit timestamps: two orders created in the same second have no
        // defined order, so the assertion would be testing the clock.
        Payment::query()->create([
            'chat_id' => '555', 'client_id' => Payment::NEW_CLIENT, 'plan_id' => '1',
            'price' => '1000', 'is_paid' => null, 'method' => PaymentMethod::Card,
            'created_at' => '2026-01-01 10:00:00',
        ]);

        Payment::query()->create([
            'chat_id' => '555', 'client_id' => Payment::NEW_CLIENT, 'plan_id' => '2',
            'price' => '2000', 'is_paid' => null, 'method' => PaymentMethod::Card,
            'created_at' => '2026-01-01 11:00:00',
        ]);

        Payment::query()->create([
            'chat_id' => '777', 'client_id' => Payment::NEW_CLIENT, 'plan_id' => '3',
            'price' => '3000', 'is_paid' => null, 'method' => PaymentMethod::Card,
            'created_at' => '2026-01-01 12:00:00',
        ]);

        $forFirst = $this->payments->forChat('555');

        $this->assertCount(2, $forFirst);
        $this->assertSame('2', $forFirst->first()?->plan_id);
        $this->assertSame('1', $forFirst->last()?->plan_id);
    }

    public function test_a_blank_search_returns_every_order(): void
    {
        $this->makeUser('555');
        $this->makeUser('777');

        $this->payments->create('555', Payment::NEW_CLIENT, '1', '1000', PaymentMethod::Card);
        $this->payments->create('777', Payment::NEW_CLIENT, '2', '2000', PaymentMethod::Card);

        $this->assertCount(2, $this->payments->search());
        $this->assertCount(2, $this->payments->search('   '));
        $this->assertSame(2, $this->payments->countSearchResults());
    }

    public function test_the_search_matches_the_order_number(): void
    {
        $this->makeUser('555');

        $payment = $this->payments->create('555', Payment::NEW_CLIENT, '1', '1000', PaymentMethod::Card);

        $results = $this->payments->search((string) $payment?->order_number);

        $this->assertCount(1, $results);
        $this->assertSame($payment?->id, $results->first()?->id);
    }

    public function test_the_search_matches_the_coupon_code(): void
    {
        $this->makeUser('555');

        $this->payments->create('555', Payment::NEW_CLIENT, '1', '1000', PaymentMethod::Card, coupon: 'SAVE10');
        $this->payments->create('555', Payment::NEW_CLIENT, '2', '2000', PaymentMethod::Card);

        $this->assertCount(1, $this->payments->search('SAVE10'));
    }

    public function test_the_search_joins_the_user_name(): void
    {
        $this->makeUser('555', 'Sara');

        $payment = $this->payments->create('555', Payment::NEW_CLIENT, '1', '1000', PaymentMethod::Card);

        $results = $this->payments->search('Sara');

        $this->assertCount(1, $results);
        $this->assertSame('Sara', $results->first()?->user_name);
        $this->assertSame($payment?->chat_id, $results->first()?->user_telegram);
    }

    public function test_the_search_paginates(): void
    {
        $this->makeUser('555');

        for ($i = 0; $i < 5; $i++) {
            $this->payments->create('555', Payment::NEW_CLIENT, (string) $i, (string) (1000 + $i), PaymentMethod::Card);
        }

        $this->assertCount(2, $this->payments->search(null, 1, 2));
        $this->assertCount(2, $this->payments->search(null, 2, 2));
        $this->assertCount(1, $this->payments->search(null, 3, 2));
    }

    public function test_it_resolves_the_buyer_of_an_order(): void
    {
        $user = $this->makeUser('555', 'Ali');
        $payment = $this->payments->create('555', Payment::NEW_CLIENT, '1', '1000', PaymentMethod::Card);

        $this->assertSame($user->id, $this->payments->buyer($payment)?->id);
    }
}
