<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WalletOperation;
use App\Enums\WalletTransactionType;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The balance/ledger transaction and the single notification legacy
 * `createWalletTransaction()` sent.
 */
class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        config(['connectix_bot.telegram.token' => 'test-token']);

        $this->wallets = $this->app->make(WalletService::class);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);
    }

    private function makeWallet(string $chatId, int $balance = 0): void
    {
        $this->wallets->create($chatId, $balance);
    }

    /**
     * The text of the first message Telegram received, or null when it received
     * none. Reads the recorded pairs directly so that "nothing was sent" is a
     * normal outcome rather than a failing assertion.
     */
    private function sentText(): ?string
    {
        foreach (Http::recorded() as [$request, $response]) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'sendMessage')) {
                return (string) $request['text'];
            }
        }

        return null;
    }

    public function test_it_increases_the_balance_and_writes_a_ledger_entry(): void
    {
        $this->makeWallet('555', 1000);

        $wallet = $this->wallets->increase('555', 500);

        $this->assertSame(1500, $wallet?->balanceAmount());

        $transaction = $this->wallets->transactionsFor('555')->first();

        $this->assertNotNull($transaction);
        $this->assertSame('500', (string) $transaction->amount);
        $this->assertSame(WalletOperation::Increase, $transaction->operation);
        $this->assertSame(WalletTransactionType::DoneByAdmin, $transaction->type);
    }

    public function test_it_decreases_the_balance(): void
    {
        $this->makeWallet('555', 1000);

        $wallet = $this->wallets->decrease('555', 250, WalletTransactionType::Buy);

        $this->assertSame(750, $wallet?->balanceAmount());
    }

    public function test_an_admin_credit_announces_the_amount(): void
    {
        $this->makeWallet('555');

        $this->wallets->increase('555', 25000, announce: true);

        $this->assertSame(
            '💰 مبلغ 25,000 تومان به کیف پول شما اضافه شد. 📈',
            $this->sentText(),
        );
    }

    public function test_an_admin_debit_announces_the_amount(): void
    {
        $this->makeWallet('555');

        $this->wallets->adjust(
            '555',
            WalletOperation::Decrease,
            5000,
            WalletTransactionType::DoneByAdmin,
            announce: true,
        );

        $this->assertSame(
            '💰 مبلغ 5,000 تومان از کیف پول شما کم شد. 📉',
            $this->sentText(),
        );
    }

    public function test_it_stays_silent_without_the_announce_flag(): void
    {
        $this->makeWallet('555');

        $this->wallets->increase('555', 25000);

        $this->assertNull($this->sentText());
    }

    /**
     * A purchase announces itself from its own flow, so the wallet must not
     * send a second message for it.
     */
    public function test_a_purchase_is_never_announced_by_the_wallet(): void
    {
        $this->makeWallet('555', 1000);

        $this->wallets->adjust(
            '555',
            WalletOperation::Decrease,
            1000,
            WalletTransactionType::Buy,
            announce: true,
        );

        $this->assertNull($this->sentText());
    }

    public function test_a_missing_wallet_is_a_no_op(): void
    {
        $this->assertNull($this->wallets->increase('missing', 100, announce: true));
        $this->assertNull($this->sentText());
    }
}
