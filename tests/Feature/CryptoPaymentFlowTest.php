<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CryptoPayment;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Crypto\CryptoConfigService;
use App\Services\Crypto\CryptoPayService;
use App\Services\User\UserStateService;
use App\Services\Wallet\WalletService;
use App\Telegram\Handlers\CryptoHandler;
use App\Telegram\TelegramUpdate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The bot-side crypto payment flow: order creation (units math, minimum,
 * expiry), hash extraction (bare / explorer link / foreign domains), the
 * on-chain verification with its attempt limit and hash-reuse guard, the
 * admin decision buttons and the `crypto_hash` user state.
 *
 * Every fixture address below is a structurally valid real-world shape
 * (base58check for Tron, CRC-checked friendly form for TON), so the codec
 * paths are exercised, not stubbed around.
 */
class CryptoPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '555';
    private const ADMIN = '999';

    /** Tron wallet: base58check of 41 + 11*20. */
    private const TRX_ADDRESS = 'TBXSw8fM4jpQkGc6zZjsVABFpVN7UvXPdV';
    private const TRX_ADDRESS_HEX = '411111111111111111111111111111111111111111';
    /** A different wallet, for the wrong-destination test. */
    private const TRX_WRONG_ADDRESS_HEX = '412222222222222222222222222222222222222222';
    /** TON wallet: friendly EQ form of 11*32. */
    private const TON_ADDRESS = 'EQAREREREREREREREREREREREREREREREREREREREREREeYT';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.base_url' => 'https://api.connectix.vip',
            'connectix_bot.connectix.panel_url' => 'https://seller.connectix.vip',
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.admin_ids' => [self::ADMIN],
            'connectix_bot.messages' => [],
        ]);

        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * Switch the feature on with the Tron (and optionally TON) wallet set.
     * Rates are manual so order creation never needs a price API.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function configure(array $overrides = []): void
    {
        app(CryptoConfigService::class)->save(array_merge([
            'crypto_enabled' => true,
            'crypto_auto_confirm' => true,
            'crypto_expiry_minutes' => 60,
            'crypto_min_toman' => 10000,
            CryptoConfigService::NETWORKS_KEY => [
                'trx' => ['enabled' => true, 'address' => self::TRX_ADDRESS, 'rate_mode' => 'manual', 'rate_toman' => 8500],
                'ton' => ['enabled' => false, 'address' => '', 'rate_mode' => 'manual', 'rate_toman' => 0],
            ],
        ], $overrides));
    }

    private function makeUser(string $chatId = self::CHAT): User
    {
        return User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => 'tg_'.$chatId,
            'name' => 'Ali',
            'test' => false,
        ]);
    }

    private function service(): CryptoPayService
    {
        return app(CryptoPayService::class);
    }

    /** A purchase state in the shape PurchaseHandler leaves behind. */
    private function purchaseState(): array
    {
        return [
            'action' => UserStateService::ACTION_BUY,
            'plan' => '11',
            'acc' => 'new',
            'price' => '120000',
            'final_price' => '120000',
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{ok: bool, text: string, order: CryptoPayment|null}
     */
    private function createOrder(string $network, array $state, string $mode = 'buy'): array
    {
        return $this->service()->createOrder(self::CHAT, $network, $mode, $state);
    }

    /** @return array<int, string> every message the bot sent or edited */
    private function sentTexts(): array
    {
        $texts = [];

        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST'
                && (str_contains($request->url(), 'sendMessage') || str_contains($request->url(), 'editMessageText'))) {
                $texts[] = (string) ($request['text'] ?? '');
            }
        }

        return $texts;
    }

    /** Replace the whole HTTP fake set (Http::fake keeps the first stub per URL). */
    private function refake(array $stubs): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(array_merge([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ], $stubs));
    }

    /**
     * A confirmed Tron transfer of $units sun to $toHex, timestamped at
     * $seconds, identified by $txId.
     *
     * @return array<string, mixed>
     */
    private function tronChain(int $units, string $toHex, ?int $seconds = null, string $txId = ''): array
    {
        return [
            'txID' => $txId !== '' ? $txId : str_repeat('ab', 32),
            'ret' => [['contractRet' => 'SUCCESS']],
            'raw_data' => [
                'timestamp' => ($seconds ?? time()) * 1000,
                'contract' => [[
                    'type' => 'TransferContract',
                    'parameter' => ['value' => [
                        'owner_address' => self::TRX_ADDRESS_HEX,
                        'to_address' => $toHex,
                        'amount' => $units,
                    ]],
                ]],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Order creation
    // -----------------------------------------------------------------

    public function test_it_creates_a_waiting_order_with_the_fork_s_units_math(): void
    {
        $this->configure();
        $this->makeUser();

        // 120,000 / 8,500 = 14.1176... coin, rounded UP to 4 decimals by
        // the fork's rule -> 14.1177 TRX = 14,117,700 sun.
        $result = $this->createOrder('trx', $this->purchaseState());

        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['order']);

        $order = CryptoPayment::query()->find($result['order']->id);

        $this->assertDatabaseHas('crypto_payments', [
            'id' => $order->id,
            'chat_id' => self::CHAT,
            'kind' => 'buy',
            'network' => 'trx',
            'status' => CryptoPayService::STATUS_WAITING,
            'amount_toman' => 120000,
            'expected_units' => 14117700,
            'pay_address' => self::TRX_ADDRESS,
        ]);
        $this->assertSame(0, (int) $order->attempts);
        $this->assertNull($order->submitted_hash);
        $this->assertNotNull($order->payment_id, 'a purchase order links a payments row');

        // Expiry: now + 60 minutes, stored as an Asia/Tehran wall clock.
        $created = Carbon::parse((string) $order->getRawOriginal('created_at'), 'Asia/Tehran')->timestamp;
        $expires = Carbon::parse((string) $order->getRawOriginal('expires_at'), 'Asia/Tehran')->timestamp;
        $this->assertSame(3600, $expires - $created);

        // The fork's display: 6 decimals, two-plus digits after the dot.
        $this->assertSame('14.1177', CryptoPayService::fmtUnits(14117700, 'trx'));

        // The order screen carries the copyable amount and our address.
        $screen = $this->service()->orderScreen($order);
        $this->assertStringContainsString('<code>14.1177</code>', $screen['text']);
        $this->assertStringContainsString(self::TRX_ADDRESS, $screen['text']);
        $this->assertStringContainsString('120,000', $screen['text']);
    }

    public function test_it_stores_the_ton_units_and_the_wallet_pending_transaction(): void
    {
        $this->configure([
            CryptoConfigService::NETWORKS_KEY => [
                'trx' => ['enabled' => false, 'address' => self::TRX_ADDRESS, 'rate_mode' => 'manual', 'rate_toman' => 8500],
                'ton' => ['enabled' => true, 'address' => self::TON_ADDRESS, 'rate_mode' => 'manual', 'rate_toman' => 20000],
            ],
        ]);
        $this->makeUser();
        Wallet::query()->create(['chat_id' => self::CHAT, 'balance' => '0']);

        // 120,000 / 20,000 = 6.0 TON exactly = 6,000,000,000 nanoTON.
        $result = $this->createOrder('ton', ['amount' => '120000'], 'wallet');

        $this->assertTrue($result['ok']);

        $order = $result['order'];

        $this->assertSame('wallet', $order->kind);
        $this->assertSame(6000000000, (int) $order->expected_units);
        $this->assertSame('6.00', CryptoPayService::fmtUnits(6000000000, 'ton'));
        $this->assertNotNull($order->wallet_tx_id);
        $this->assertNull($order->payment_id);
        $this->assertDatabaseHas('wallet_transactions', [
            'id' => $order->wallet_tx_id,
            'status' => 'PENDING',
            'operation' => 'INCREASE',
            'amount' => '120000',
        ]);
    }

    public function test_an_order_below_the_minimum_is_refused(): void
    {
        $this->configure(['crypto_min_toman' => 50000]);
        $this->makeUser();

        $result = $this->createOrder('trx', ['amount' => '40000'], 'wallet');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('حداقل مبلغ', $result['text']);
        $this->assertStringContainsString('50,000', $result['text']);
        $this->assertDatabaseCount('crypto_payments', 0);
    }

    // -----------------------------------------------------------------
    // Hash extraction
    // -----------------------------------------------------------------

    public function test_extract_hash_accepts_a_bare_hash_and_an_explorer_link(): void
    {
        $hash = str_repeat('ab12cd34', 8); // 64 hex chars

        $bare = $this->service()->extractHash($hash, 'trx');
        $this->assertTrue($bare['ok']);
        $this->assertSame($hash, $bare['hash']);

        // Upper case normalises down to the canonical form.
        $upper = $this->service()->extractHash(strtoupper($hash), 'trx');
        $this->assertTrue($upper['ok']);
        $this->assertSame($hash, $upper['hash']);

        $link = $this->service()->extractHash('https://tronscan.org/#/transaction/'.$hash, 'trx');
        $this->assertTrue($link['ok']);
        $this->assertSame($hash, $link['hash']);
    }

    public function test_extract_hash_rejects_disallowed_and_look_alike_domains(): void
    {
        $hash = str_repeat('ab12cd34', 8);

        $unknown = $this->service()->extractHash('https://example.com/tx/'.$hash, 'trx');
        $this->assertFalse($unknown['ok']);
        $this->assertStringContainsString('ناشناس', $unknown['error']);

        // tronscan.org.evil.com must not pass as tronscan.org.
        $lookAlike = $this->service()->extractHash('https://tronscan.org.evil.com/#/transaction/'.$hash, 'trx');
        $this->assertFalse($lookAlike['ok']);

        // A TON link in front of a TRX order names the mismatch.
        $wrongNetwork = $this->service()->extractHash('https://tonviewer.com/transaction/'.$hash, 'trx');
        $this->assertFalse($wrongNetwork['ok']);
        $this->assertStringContainsString('شبکه', $wrongNetwork['error']);

        // One message, two different hashes: refuse instead of guessing.
        $two = $this->service()->extractHash($hash.' '.str_repeat('cd34ab12', 8), 'trx');
        $this->assertFalse($two['ok']);
    }

    // -----------------------------------------------------------------
    // Verification
    // -----------------------------------------------------------------

    public function test_a_clean_transfer_auto_confirms_and_credits_the_wallet(): void
    {
        $this->configure();
        $this->makeUser();
        Wallet::query()->create(['chat_id' => self::CHAT, 'balance' => '0']);

        $result = $this->createOrder('trx', ['amount' => '120000'], 'wallet');
        $order = $result['order'];
        $units = (int) $order->expected_units;
        $hash = str_repeat('ab12cd34', 8);

        $this->refake([
            'https://api.trongrid.io/walletsolidity/gettransactionbyid' => Http::response(
                $this->tronChain($units, self::TRX_ADDRESS_HEX, time(), $hash)
            ),
        ]);

        $outcome = $this->service()->submitHash(self::CHAT, $hash, (int) $order->id);

        $this->assertSame(CryptoPayService::STATUS_COMPLETED, $outcome['status']);
        $this->assertStringContainsString('تایید شد', $outcome['text']);

        $order->refresh();
        $this->assertSame(CryptoPayService::STATUS_COMPLETED, $order->status);
        $this->assertSame($hash, $order->tx_hash);
        $this->assertSame($units, (int) $order->received_units);

        // The money landed: balance credited, pending deposit settled.
        $this->assertSame(120000, app(WalletService::class)->balance(self::CHAT));
        $this->assertDatabaseHas('wallet_transactions', [
            'id' => $order->wallet_tx_id,
            'status' => 'SUCCESS',
        ]);

        // The hash is burned forever.
        $this->assertDatabaseHas('crypto_hashes', [
            'network' => 'trx',
            'hash' => $hash,
            'order_id' => $order->id,
        ]);
    }

    public function test_a_transfer_to_the_wrong_wallet_is_rejected_and_costs_an_attempt(): void
    {
        $this->configure();
        $this->makeUser();

        $result = $this->createOrder('trx', $this->purchaseState());
        $order = $result['order'];
        $hash = str_repeat('ab12cd34', 8);

        $this->refake([
            'https://api.trongrid.io/walletsolidity/gettransactionbyid' => Http::response(
                $this->tronChain((int) $order->expected_units, self::TRX_WRONG_ADDRESS_HEX, time(), $hash)
            ),
        ]);

        $outcome = $this->service()->submitHash(self::CHAT, $hash, (int) $order->id);

        $this->assertSame(CryptoPayService::STATUS_WAITING, $outcome['status']);
        $this->assertStringContainsString('آدرس مقصد این تراکنش، آدرس کیف پول ما نیست', $outcome['text']);
        // Attempt 1 of 5 is spent, so four tries remain (the fork's count).
        $this->assertStringContainsString('4 تلاش باقی مانده', $outcome['text']);

        $order->refresh();
        $this->assertSame(CryptoPayService::STATUS_WAITING, $order->status);
        $this->assertSame(1, (int) $order->attempts);
        $this->assertNull($order->submitted_hash, 'a rejected hash is handed back for a new one');

        // The rejected hash is remembered, so ANOTHER order cannot reuse it.
        $second = $this->createOrder('trx', $this->purchaseState());
        $this->assertTrue($second['ok']);

        $again = $this->service()->submitHash(self::CHAT, $hash, (int) $second['order']->id);

        $this->assertSame(CryptoPayService::STATUS_WAITING, $again['status']);
        $this->assertStringContainsString('قبلاً ثبت شده', $again['text']);
        $this->assertSame(0, (int) CryptoPayment::query()->find($second['order']->id)->attempts);
    }

    public function test_the_sixth_attempt_is_refused_and_the_order_closes(): void
    {
        $this->configure();
        $this->makeUser();

        $result = $this->createOrder('trx', $this->purchaseState());
        $order = $result['order'];
        // Five verifications already consumed (the fork's MAX_ATTEMPTS).
        $order->forceFill(['attempts' => CryptoPayService::MAX_ATTEMPTS])->save();

        $outcome = $this->service()->submitHash(self::CHAT, str_repeat('ab12cd34', 8), (int) $order->id);

        $this->assertSame(CryptoPayService::STATUS_REJECTED, $outcome['status']);
        $this->assertStringContainsString('تعداد تلاشهای مجاز به پایان رسید', $outcome['text']);

        $this->assertDatabaseHas('crypto_payments', [
            'id' => $order->id,
            'status' => CryptoPayService::STATUS_REJECTED,
            'attempts' => CryptoPayService::MAX_ATTEMPTS,
        ]);
    }

    // -----------------------------------------------------------------
    // Admin decision
    // -----------------------------------------------------------------

    public function test_an_admin_can_confirm_and_a_stranger_gets_the_fork_s_403(): void
    {
        $this->configure();
        $this->makeUser();
        $stranger = $this->makeUser('12345');
        Wallet::query()->create(['chat_id' => self::CHAT, 'balance' => '0']);

        $result = $this->createOrder('trx', ['amount' => '120000'], 'wallet');
        $order = $result['order'];
        $order->forceFill([
            'status' => CryptoPayService::STATUS_REVIEW,
            'submitted_hash' => str_repeat('ab12cd34', 8),
        ])->save();

        // The service-level decision settles the wallet order.
        $decision = $this->service()->adminDecide((int) $order->id, true, 'panel');
        $this->assertTrue($decision['ok']);

        $order->refresh();
        $this->assertSame(CryptoPayService::STATUS_COMPLETED, $order->status);
        $this->assertSame(120000, app(WalletService::class)->balance(self::CHAT));

        // Repeating it reports the order as already decided.
        $again = $this->service()->adminDecide((int) $order->id, true, 'panel');
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('قبلاً بررسی', $again['message']);

        // The button-level gate: an admin claim passes, everyone else gets
        // the fork's 403 text.
        $handler = app(CryptoHandler::class);

        $this->assertTrue($handler->supports($this->press('crypto_ok:'.$order->id, self::ADMIN), $stranger));

        $handler->handle($this->press('crypto_ok:'.$order->id, '12345'), $stranger);

        $this->assertContains('⛔ دسترسی غیرمجاز.', $this->sentTexts());
    }

    // -----------------------------------------------------------------
    // Handler
    // -----------------------------------------------------------------

    public function test_the_handler_only_claims_crypto_callbacks(): void
    {
        $this->configure();
        $user = $this->makeUser();
        $handler = app(CryptoHandler::class);

        $this->assertTrue($handler->supports($this->press('crypto_menu:buy'), $user));
        $this->assertTrue($handler->supports($this->press('crypto_net:wallet:ton'), $user));
        $this->assertTrue($handler->supports($this->press('crypto_chk:1'), $user));
        $this->assertTrue($handler->supports($this->press('crypto_ok:1'), $user));
        $this->assertTrue($handler->supports($this->press('crypto_no:1'), $user));
        $this->assertTrue($handler->supports($this->press('crypto_cancel:1'), $user));

        $this->assertFalse($handler->supports($this->press('main_menu'), $user));
        $this->assertFalse($handler->supports($this->press('wallet_increase:0'), $user));
        $this->assertFalse($handler->supports($this->press('payment_accept:1'), $user));
        $this->assertFalse($handler->supports($this->message('/start'), $user));

        // A plain message is claimed only while the crypto_hash state is set.
        $this->assertFalse($handler->supports($this->message(str_repeat('ab12cd34', 8)), $user));

        $user->forceFill(['action' => json_encode([
            'action' => CryptoPayService::ACTION_HASH,
            CryptoPayService::STATE_ORDER_KEY => 7,
        ])])->save();

        $this->assertTrue($handler->supports($this->message(str_repeat('ab12cd34', 8)), $user));
    }

    public function test_the_net_callback_builds_the_order_and_arms_the_hash_state(): void
    {
        $this->configure();
        $user = $this->makeUser();
        $user->forceFill(['action' => json_encode($this->purchaseState())])->save();

        app(CryptoHandler::class)->handle($this->press('crypto_net:buy:trx'), $user);

        $order = CryptoPayment::query()->sole();

        $this->assertDatabaseHas('crypto_payments', [
            'id' => $order->id,
            'status' => CryptoPayService::STATUS_WAITING,
        ]);

        $state = app(UserStateService::class)->get($user);
        $this->assertNotNull($state);
        $this->assertSame(CryptoPayService::ACTION_HASH, $state['action']);
        $this->assertSame((int) $order->id, (int) $state[CryptoPayService::STATE_ORDER_KEY]);

        // The screen the customer sees: address plus refresh/cancel buttons.
        $texts = $this->sentTexts();
        $this->assertNotEmpty($texts);
        $this->assertStringContainsString(self::TRX_ADDRESS, end($texts));

        $keyboardSeen = null;

        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST'
                && (str_contains($request->url(), 'sendMessage') || str_contains($request->url(), 'editMessageText'))) {
                $keyboardSeen = json_decode((string) ($request['reply_markup'] ?? ''), true);
            }
        }

        $this->assertNotNull($keyboardSeen);

        $callbacks = [];

        foreach ($keyboardSeen['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                $callbacks[] = $button['callback_data'];
            }
        }

        $this->assertSame(['crypto_ref:'.$order->id, 'crypto_cancel:'.$order->id], $callbacks);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function press(string $data, int|string $chatId = self::CHAT): TelegramUpdate
    {
        return TelegramUpdate::fromArray([
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb-crypto',
                'from' => ['id' => (int) $chatId, 'is_bot' => false, 'first_name' => 'Ali'],
                'message' => [
                    'message_id' => 42,
                    'chat' => ['id' => (int) $chatId],
                    'text' => 'menu',
                ],
                'data' => $data,
            ],
        ]);
    }

    private function message(string $text): TelegramUpdate
    {
        return TelegramUpdate::fromArray([
            'update_id' => 2,
            'message' => [
                'message_id' => 43,
                'chat' => ['id' => (int) self::CHAT],
                'from' => ['id' => (int) self::CHAT, 'is_bot' => false, 'first_name' => 'Ali'],
                'text' => $text,
            ],
        ]);
    }
}