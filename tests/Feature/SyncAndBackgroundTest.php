<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WalletOperation;
use App\Enums\WalletTransactionType;
use App\Models\Client;
use App\Models\HandledUpdate;
use App\Models\Payment;
use App\Models\SmsPayment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The background/sync half of the rewrite: the commands legacy ran from the
 * browser, the update deduplication and the housekeeping.
 *
 * Legacy `update/clients_update.php` and `update/users.php` could only be
 * started by hand and had no lock, no budget and no resume; these tests pin the
 * behaviour that replaces them.
 */
class SyncAndBackgroundTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.connectix.vip';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
        ]);
    }

    // -----------------------------------------------------------------
    // connectix:sync-clients
    // -----------------------------------------------------------------

    #[Test]
    public function it_syncs_a_panel_client_into_users_and_clients(): void
    {
        Http::fake([
            self::BASE.'/v1/seller?page=*' => Http::response([
                'clients' => [
                    'data' => [['id' => 'abc']],
                    'total' => 1,
                ],
            ], 200),
            self::BASE.'/v1/seller/clients/show?id=*' => Http::response([
                'client' => [
                    'id' => 'abc',
                    'chat_id' => '553',
                    'telegram_id' => '@buyer',
                    'name' => 'Buyer',
                    'email' => 'buyer@example.com',
                    'phone' => '09120000000',
                    'count_of_devices' => 2,
                    'username' => 'buyer',
                    'password' => 'secret',
                ],
            ], 200),
        ]);

        $this->artisan('connectix:sync-clients')->assertSuccessful();

        $user = User::query()->where('chat_id', '553')->first();
        $client = Client::query()->find('abc');

        $this->assertNotNull($user);
        $this->assertSame('@buyer', $user->telegram_id);
        $this->assertSame('buyer@example.com', $user->email);
        $this->assertFalse((bool) $user->test);

        $this->assertNotNull($client);
        $this->assertSame(2, (int) $client->count_of_devices);
        $this->assertSame('buyer', $client->username);
        $this->assertSame($user->id, (int) $client->user_id);
    }

    #[Test]
    public function syncing_updates_an_existing_user_without_resetting_the_test_flag(): void
    {
        // Legacy wrote `test = 1` on every row it touched, which turned paying
        // customers into test accounts the next time the sync ran.
        $user = $this->makeUser('553', ['telegram_id' => 'old', 'name' => 'Old', 'test' => true]);
        Client::query()->create([
            'id' => 'abc',
            'count_of_devices' => 1,
            'username' => 'buyer',
            'password' => 'secret',
            'chat_id' => '553',
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        Http::fake([
            self::BASE.'/v1/seller?page=*' => Http::response([
                'clients' => ['data' => [['id' => 'abc']]],
            ], 200),
            self::BASE.'/v1/seller/clients/show?id=*' => Http::response([
                'client' => [
                    'id' => 'abc',
                    'chat_id' => '553',
                    'telegram_id' => '@fresh',
                    'name' => 'Fresh',
                    'count_of_devices' => 3,
                    'username' => 'buyer',
                    'password' => 'new-secret',
                ],
            ], 200),
        ]);

        $this->artisan('connectix:sync-clients')->assertSuccessful();

        $user->refresh();

        $this->assertSame('@fresh', $user->telegram_id);
        $this->assertSame('Fresh', $user->name);
        $this->assertTrue((bool) $user->test, 'The local test flag must survive a panel sync.');
        $this->assertSame(1, User::query()->count());
        $this->assertSame(3, (int) Client::query()->find('abc')->count_of_devices);
    }

    #[Test]
    public function syncing_skips_a_client_whose_detail_cannot_be_read(): void
    {
        Http::fake([
            self::BASE.'/v1/seller?page=*' => Http::response([
                'clients' => ['data' => [['id' => 'abc']]],
            ], 200),
            self::BASE.'/v1/seller/clients/show?id=*' => Http::response(['client' => null], 200),
        ]);

        $this->artisan('connectix:sync-clients')
            ->expectsOutputToContain('Done. 0 clients processed, 0 users created, 0 clients created, 1 skipped.')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertNull(Client::query()->find('abc'));
    }

    #[Test]
    public function syncing_a_second_time_does_not_duplicate_anything(): void
    {
        $this->fakePanelClient('abc', '553');

        $this->artisan('connectix:sync-clients')->assertSuccessful();
        $this->artisan('connectix:sync-clients')->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Client::query()->count());
    }

    #[Test]
    public function a_second_sync_refuses_to_start_while_the_first_holds_the_lock(): void
    {
        $this->fakePanelClient('abc', '553');

        $this->app['cache']->lock('connectix:sync-clients', 3600)->get();

        $this->artisan('connectix:sync-clients')
            ->expectsOutputToContain('Another client sync is already running.')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    // -----------------------------------------------------------------
    // connectix:sync-users
    // -----------------------------------------------------------------

    #[Test]
    public function it_refreshes_the_cached_profile_of_a_user(): void
    {
        $user = $this->makeUser('553', ['telegram_id' => '@buyer', 'name' => 'stale']);

        Http::fake([
            'https://t.me/buyer' => Http::response(
                '<meta property="og:image" content="https://t.me/i/avatar.jpg">'
                .'<div class="tgme_page_title"><span>Fresh Name</span></div>',
                200
            ),
        ]);

        $this->artisan('connectix:sync-users', ['--delay' => 0])
            ->expectsOutputToContain('Done. 1 checked, 1 updated, 0 skipped.')
            ->assertSuccessful();

        $user->refresh();

        $this->assertSame('Fresh Name', $user->name);
        $this->assertSame('https://t.me/i/avatar.jpg', $user->avatar);
    }

    #[Test]
    public function a_failed_profile_fetch_skips_one_user_and_keeps_going(): void
    {
        $this->makeUser('553', ['telegram_id' => '@gone', 'name' => 'Keep']);
        $this->makeUser('554', ['telegram_id' => '@here', 'name' => 'Stale']);

        Http::fake([
            'https://t.me/gone' => Http::response('', 404),
            'https://t.me/here' => Http::response(
                '<div class="tgme_page_title"><span>Here Now</span></div>',
                200
            ),
        ]);

        $this->artisan('connectix:sync-users', ['--delay' => 0])
            ->expectsOutputToContain('Done. 2 checked, 1 updated, 1 skipped.')
            ->assertSuccessful();

        $this->assertSame('Keep', User::query()->where('chat_id', '553')->first()->name);
        $this->assertSame('Here Now', User::query()->where('chat_id', '554')->first()->name);
    }

    #[Test]
    public function the_user_sync_stops_at_the_budget_and_offers_a_resume_point(): void
    {
        $this->makeUser('553', ['telegram_id' => '@one']);
        $this->makeUser('554', ['telegram_id' => '@two']);

        Http::fake([
            'https://t.me/*' => Http::response(
                '<div class="tgme_page_title"><span>Name</span></div>',
                200
            ),
        ]);

        $this->artisan('connectix:sync-users', ['--limit' => 1, '--delay' => 0])
            ->expectsOutputToContain('--from=')
            ->assertSuccessful();

        $first = User::query()->where('chat_id', '553')->first();
        $second = User::query()->where('chat_id', '554')->first();

        $this->assertSame('Name', $first->name);
        $this->assertNull($second->name, 'The second user is left for the next run.');
    }

    // -----------------------------------------------------------------
    // connectix:prune
    // -----------------------------------------------------------------

    #[Test]
    public function the_prune_deletes_expired_deposits_but_keeps_matched_ones(): void
    {
        $this->makeUser('553');
        $paymentId = $this->makePayment();

        $expired = $this->makeSms(['amount' => 100, 'expired_at' => now()->subMinute()]);
        $matched = $this->makeSms([
            'amount' => 200,
            'expired_at' => now()->subMinute(),
            'payment_id' => (string) $paymentId,
        ]);
        $waiting = $this->makeSms(['amount' => 300, 'expired_at' => now()->addMinutes(4)]);

        $this->artisan('connectix:prune')->assertSuccessful();

        $this->assertNull($expired->fresh());
        $this->assertNotNull($matched->fresh(), 'A matched deposit is the audit trail and must be kept.');
        $this->assertNotNull($waiting->fresh());
    }

    #[Test]
    public function the_prune_drops_old_update_ledger_rows_but_keeps_recent_ones(): void
    {
        $old = HandledUpdate::query()->create(['update_id' => 1, 'handled_at' => now()->subDays(3)]);
        $recent = HandledUpdate::query()->create(['update_id' => 2, 'handled_at' => now()]);

        $this->artisan('connectix:prune')
            ->expectsOutputToContain('1 handled updates')
            ->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($recent->fresh());
    }

    // -----------------------------------------------------------------
    // Update deduplication
    // -----------------------------------------------------------------

    #[Test]
    public function the_same_telegram_update_is_only_handled_once(): void
    {
        config([
            'connectix_bot.telegram.webhook_secret' => 'a-very-long-random-secret',
            'connectix_bot.active' => true,
        ]);

        $this->makeUser('553');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => 553], 'text' => '/start'],
            ], 200),
        ]);

        $update = [
            'update_id' => 4242,
            'message' => [
                'message_id' => 1,
                'date' => time(),
                'chat' => ['id' => 553],
                'from' => ['id' => 553, 'first_name' => 'Buyer', 'username' => 'buyer'],
                'text' => '/start',
            ],
        ];

        $this->postJson('/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'a-very-long-random-secret'])
            ->assertOk();

        $sentAfterFirst = $this->telegramSends();

        // Telegram redelivered the same update because the first answer was
        // slow; the answer must still be a 200, and nothing may be sent twice.
        $this->postJson('/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'a-very-long-random-secret'])
            ->assertOk();

        $this->assertGreaterThan(0, $sentAfterFirst);
        $this->assertCount(
            count($sentAfterFirst),
            $this->telegramSends(),
            'A redelivered update must not act twice.'
        );
        $this->assertSame(1, HandledUpdate::query()->where('update_id', 4242)->count());
    }

    #[Test]
    public function an_update_without_an_id_is_never_deduplicated(): void
    {
        $claim = HandledUpdate::claim(0);

        $this->assertTrue($claim, 'An update without an id must not poison the ledger.');
        $this->assertSame(0, HandledUpdate::query()->count());
    }

    // -----------------------------------------------------------------
    // connectix:sync-wallets
    // -----------------------------------------------------------------

    #[Test]
    public function it_imports_a_panel_wallet_with_its_transaction_history(): void
    {
        $this->fakePanelWallets();

        $this->artisan('connectix:sync-wallets')->assertSuccessful();

        $wallet = Wallet::query()->where('chat_id', '553')->first();

        $this->assertNotNull($wallet);
        $this->assertSame(1250000, $wallet->balanceAmount());
        $this->assertSame(2, $wallet->transactions()->count());

        $deposit = $wallet->transactions()->orderBy('id')->first();
        $purchase = $wallet->transactions()->orderByDesc('id')->first();

        $this->assertSame(1250000, $deposit->amount);
        $this->assertSame(WalletOperation::Increase, $deposit->operation);
        $this->assertSame(WalletTransactionType::CardToCard, $deposit->type);
        $this->assertSame('2024-07-31 10:00:00', $deposit->created_at->toDateTimeString());

        // The panel sends no transaction id for a purchase, which legacy turned
        // into a BUY, and its timestamp is Jalali.
        $this->assertSame(200000, $purchase->amount);
        $this->assertSame(WalletOperation::Decrease, $purchase->operation);
        $this->assertSame(WalletTransactionType::Buy, $purchase->type);
        $this->assertSame('2024-08-02 14:30:00', $purchase->created_at->toDateTimeString());
    }

    #[Test]
    public function syncing_wallets_twice_does_not_duplicate_the_history(): void
    {
        $this->fakePanelWallets();

        $this->artisan('connectix:sync-wallets')->assertSuccessful();
        $this->artisan('connectix:sync-wallets')
            ->expectsOutputToContain('0 transactions')
            ->assertSuccessful();

        $this->assertSame(1, Wallet::query()->count());
        $this->assertSame(2, WalletTransaction::query()->count());
    }

    #[Test]
    public function a_wallet_without_a_chat_id_is_skipped(): void
    {
        Http::fake([
            self::BASE.'/v1/seller/telegram-wallets' => Http::response([
                'data' => [['id' => 'w1', 'chat_id' => 'null', 'balance' => '500']],
            ], 200),
        ]);

        $this->artisan('connectix:sync-wallets')
            ->expectsOutputToContain('1 skipped')
            ->assertSuccessful();

        $this->assertSame(0, Wallet::query()->count());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * A panel wallet list with the Jalali timestamps and the amount separators
     * legacy `setup.php` had to cope with.
     */
    private function fakePanelWallets(): void
    {
        Http::fake([
            self::BASE.'/v1/seller/telegram-wallets' => Http::response([
                'data' => [['id' => 'w1', 'chat_id' => '553', 'balance' => '1,250,000']],
            ], 200),
            self::BASE.'/v1/seller/telegram-wallets/w1*' => Http::response([
                'wallet' => [
                    'transactions' => [
                        [
                            'amount' => '1,250,000',
                            'type' => 'INCREASE',
                            'transaction_id' => 'CARD_TO_CARD',
                            'status' => 'SUCCESS',
                            'created_at' => '1403-05-10 10:00:00',
                        ],
                        [
                            'amount' => '200,000',
                            'type' => 'DECREASE',
                            'transaction_id' => null,
                            'status' => 'SUCCESS',
                            'created_at' => '1403-05-12 14:30:00',
                        ],
                    ],
                ],
            ], 200),
        ]);
    }

    private function fakePanelClient(string $clientId, string $chatId): void
    {
        Http::fake([
            self::BASE.'/v1/seller?page=*' => Http::response([
                'clients' => ['data' => [['id' => $clientId]]],
            ], 200),
            self::BASE.'/v1/seller/clients/show?id=*' => Http::response([
                'client' => [
                    'id' => $clientId,
                    'chat_id' => $chatId,
                    'telegram_id' => '@buyer',
                    'name' => 'Buyer',
                    'count_of_devices' => 1,
                    'username' => 'buyer',
                    'password' => 'secret',
                ],
            ], 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeUser(string $chatId, array $attributes = []): User
    {
        $user = User::query()->create(array_merge([
            'chat_id' => $chatId,
            'name' => null,
            'test' => false,
            'created_at' => now(),
        ], $attributes));

        Wallet::query()->create([
            'chat_id' => $chatId,
            'balance' => 0,
            'created_at' => now(),
        ]);

        return $user;
    }

    private function makePayment(): int
    {
        return (int) Payment::query()->create([
            'order_number' => '1',
            'chat_id' => '553',
            'client_id' => 'abc',
            'plan_id' => '1',
            'price' => '20000',
            'coupon' => null,
            'is_paid' => '',
            'method' => 'wallet',
            'created_at' => now(),
        ])->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSms(array $attributes = []): SmsPayment
    {
        return SmsPayment::query()->create(array_merge([
            'message' => 'deposit',
            'amount' => 100,
            'bank' => 'blu',
            'payment_id' => null,
            'payment_type' => null,
            'expired_at' => now()->addMinutes(5),
            'created_at' => now(),
        ], $attributes));
    }

    /**
     * @return array<int, array<string, mixed>> The sendMessage calls made so far.
     */
    private function telegramSends(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'sendMessage'))
            ->map(fn (array $pair): array => (array) $pair[0]->data())
            ->values()
            ->all();
    }
}
