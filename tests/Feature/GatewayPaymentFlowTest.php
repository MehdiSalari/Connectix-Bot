<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Pay\GatewayCallbackController;
use App\Models\GatewayOrder;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Gateways\OnlineGatewayService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewayPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 55555;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.base_url' => 'https://api.connectix.vip',
            'connectix_bot.connectix.panel_url' => 'https://seller.connectix.vip',
            'connectix_bot.active' => true,
            'connectix_bot.admin_ids' => ['1'],
            'connectix_bot.messages' => [],
            'connectix_bot.panel.enabled' => false,
        ]);
        Http::preventStrayRequests();
    }

    private function makeUser(int $balance = 0): User
    {
        $user = User::query()->create([
            'chat_id' => (string) self::CHAT,
            'telegram_id' => (string) self::CHAT,
            'name' => 'Ali',
            'test' => false,
        ]);
        if ($balance > 0) {
            $this->app->make(WalletService::class)->create((string) self::CHAT, $balance);
        }

        return $user;
    }

    private function seedZarinpal(): void
    {
        PaymentGateway::query()->create([
            'gateway_name' => 'zarinpal',
            'title' => 'درگاه زرین‌پال',
            'is_active' => true,
            'api_key' => 'test-merchant-id-36chars........',
            'merchant_id' => 'test-merchant-id-36chars........',
            'currency' => 'IRR',
            'min_amount' => 10000,
            'sort_order' => 10,
        ]);
    }

    private function seedNextpay(): void
    {
        PaymentGateway::query()->create([
            'gateway_name' => 'nextpay',
            'title' => 'درگاه نکست‌پی',
            'is_active' => true,
            'api_key' => 'np-test-key',
            'currency' => 'IRT',
            'min_amount' => 10000,
            'sort_order' => 20,
        ]);
    }

    private function seedNowpayments(): void
    {
        PaymentGateway::query()->create([
            'gateway_name' => 'nowpayments',
            'title' => 'درگاه NOWPayments (کریپتو)',
            'is_active' => true,
            'api_key' => 'np-key-test',
            'config_json' => json_encode(['usd_rate_toman' => 98000]),
            'currency' => 'IRT',
            'min_amount' => 10000,
            'sort_order' => 30,
        ]);
    }

    public function test_button_rows_empty_when_no_active_driver(): void
    {
        $rows = OnlineGatewayService::buttonRows('buy');
        $this->assertSame([], $rows);
    }

    public function test_button_rows_returns_active_zarinpal(): void
    {
        $this->seedZarinpal();
        $rows = OnlineGatewayService::buttonRows('buy');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('زرین‌پال', $rows[0][0]['text']);
        $this->assertStringContainsString('gw_go:zarinpal:buy', $rows[0][0]['callback_data']);
    }

    public function test_button_rows_wallet_mode(): void
    {
        $this->seedZarinpal();
        $rows = OnlineGatewayService::buttonRows('wallet');
        $this->assertStringContainsString('gw_go:zarinpal:wallet', $rows[0][0]['callback_data']);
    }

    public function test_create_order_zarinpal_persists_order_and_pay_url(): void
    {
        $this->seedZarinpal();
        $this->makeUser();
        $service = $this->app->make(OnlineGatewayService::class);
        Http::fake([
            'https://id.zarinpal.com/pg/v4/PaymentRequest' => Http::response([
                'data' => ['code' => 100, 'authority' => 'test-authority-123', 'pay_url' => 'https://id.zarinpal.com/pg/StartPay/test-authority-123'],
            ], 200),
        ]);
        $result = $service->createOrder((string) self::CHAT, 'zarinpal', 'buy', ['price' => '25000', 'action' => 'buy', 'acc' => 'new'], 25000);
        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['order']);
        $this->assertStringContainsString('test-authority-123', $result['order']->pay_url);
        $this->assertNotNull($result['order']->token);
        $this->assertSame('WAITING', $result['order']->status);
    }

    public function test_create_order_nextpay_fake_path(): void
    {
        $this->seedNextpay();
        $this->makeUser();
        $service = $this->app->make(OnlineGatewayService::class);
        Http::fake([
            'https://api.nextpay.ir/np/gateway/token' => Http::response([
                'code' => -1, 'token' => 'nextpay-trans-456',
            ], 200),
        ]);
        $result = $service->createOrder((string) self::CHAT, 'nextpay', 'wallet', ['amount' => 50000], 50000);
        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['order']);
        $this->assertStringContainsString('nextpay-trans-456', $result['order']->pay_url);
        $this->assertSame('wallet', $result['order']->kind);
    }

    public function test_create_order_nowpayments_fake_path(): void
    {
        $this->seedNowpayments();
        $this->makeUser();
        $service = $this->app->make(OnlineGatewayService::class);
        Http::fake([
            'https://api.nowpayments.io/v1/invoice' => Http::response([
                'id' => 'np-invoice-789', 'invoice_url' => 'https://nowpayments.io/pay/np-invoice-789',
            ], 201),
        ]);
        $result = $service->createOrder((string) self::CHAT, 'nowpayments', 'wallet', ['amount' => 200000], 200000);
        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['order']);
        $this->assertStringContainsString('np-invoice-789', $result['order']->pay_url);
        $this->assertSame('wallet', $result['order']->kind);
        $this->assertNotNull($result['order']->usd_amount);
    }

    public function test_min_amount_refusal(): void
    {
        $this->seedZarinpal();
        $this->makeUser();
        $service = $this->app->make(OnlineGatewayService::class);
        $result = $service->createOrder((string) self::CHAT, 'zarinpal', 'buy', ['price' => '5000'], 5000);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('حداقل مبلغ', $result['text']);
    }

    public function test_verify_and_finalize_zarinpal_paid_credits_wallet(): void
    {
        $this->seedZarinpal();
        $this->makeUser();
        $this->app->make(WalletService::class)->create((string) self::CHAT, 0);
        $order = GatewayOrder::query()->create([
            'token' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
            'chat_id' => (string) self::CHAT,
            'kind' => 'wallet',
            'wallet_tx_id' => 1,
            'gateway' => 'zarinpal',
            'amount_toman' => 25000,
            'amount_sent' => 250000,
            'status' => 'WAITING',
            'authority' => 'test-authority',
            'pay_url' => 'https://id.zarinpal.com/pg/StartPay/test-authority',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        WalletTransaction::query()->create([
            'wallet_id' => Wallet::query()->where('chat_id', (string) self::CHAT)->first()->id,
            'amount' => '25000',
            'operation' => 'INCREASE',
            'chat_id' => (string) self::CHAT,
            'status' => 'PENDING',
            'type' => 'CARD_TO_CARD',
            'created_at' => now(),
        ]);
        $service = $this->app->make(OnlineGatewayService::class);
        Http::fake([
            'https://id.zarinpal.com/pg/v4/PaymentVerify' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 'zp-ref-123'],
            ], 200),
        ]);
        $result = $service->verifyAndFinalize($order->id);
        $this->assertSame('PAID', $result['status']);
        $this->assertDatabaseHas('gateway_orders', ['id' => $order->id, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('wallet_transactions', ['status' => 'SUCCESS']);
    }

    public function test_verify_and_finalize_unknown_status_returns_failed(): void
    {
        $this->seedZarinpal();
        $this->makeUser();
        $order = GatewayOrder::query()->create([
            'token' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
            'chat_id' => (string) self::CHAT,
            'kind' => 'buy',
            'gateway' => 'zarinpal',
            'amount_toman' => 25000,
            'amount_sent' => 250000,
            'status' => 'WAITING',
            'authority' => 'test-authority',
            'pay_url' => 'https://id.zarinpal.com/pg/StartPay/test-authority',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = $this->app->make(OnlineGatewayService::class);
        Http::fake([
            'https://id.zarinpal.com/pg/v4/PaymentVerify' => Http::response([
                'data' => ['code' => 111],
            ], 200),
        ]);
        $result = $service->verifyAndFinalize($order->id);
        $this->assertSame('FAILED', $result['status']);
    }

    public function test_payments_row_created_with_method_gateway_for_kind_buy(): void
    {
        $this->seedZarinpal();
        $this->makeUser();
        $order = GatewayOrder::query()->create([
            'token' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
            'chat_id' => (string) self::CHAT,
            'kind' => 'buy',
            'gateway' => 'zarinpal',
            'amount_toman' => 25000,
            'amount_sent' => 250000,
            'status' => 'WAITING',
            'authority' => 'test-authority',
            'pay_url' => 'https://id.zarinpal.com/pg/StartPay/test-authority',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = $this->app->make(OnlineGatewayService::class);
        Http::fake([
            'https://id.zarinpal.com/pg/v4/PaymentVerify' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 'zp-ref-123'],
            ], 200),
        ]);
        $result = $service->verifyAndFinalize($order->id);
        $this->assertSame('PAID', $result['status']);
        $this->assertDatabaseHas('payments', [
            'method' => 'gateway',
            'is_paid' => null,
        ]);
    }

    public function test_callback_success_path_via_controller(): void
    {
        $this->seedZarinpal();
        $this->makeUser();
        $this->app->make(WalletService::class)->create((string) self::CHAT, 0);
        $order = GatewayOrder::query()->create([
            'token' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
            'chat_id' => (string) self::CHAT,
            'kind' => 'wallet',
            'wallet_tx_id' => 1,
            'gateway' => 'zarinpal',
            'amount_toman' => 25000,
            'amount_sent' => 250000,
            'status' => 'WAITING',
            'authority' => 'test-authority',
            'pay_url' => 'https://id.zarinpal.com/pg/StartPay/test-authority',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        WalletTransaction::query()->create([
            'wallet_id' => Wallet::query()->where('chat_id', (string) self::CHAT)->first()->id,
            'amount' => '25000',
            'operation' => 'INCREASE',
            'chat_id' => (string) self::CHAT,
            'status' => 'PENDING',
            'type' => 'CARD_TO_CARD',
            'created_at' => now(),
        ]);
        Http::fake([
            'https://id.zarinpal.com/pg/v4/PaymentVerify' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 'zp-ref-123'],
            ], 200),
        ]);
        $controller = $this->app->make(GatewayCallbackController::class);
        $request = new Request;
        $request->query->set('gw', 'zarinpal');
        $request->query->set('t', 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        $request->query->set('Authority', 'test-authority');
        $request->query->set('Status', 'OK');
        $request->server->set('QUERY_STRING', 'gw=zarinpal&t=a1b2c3d4e5f60718293a4b5c6d7e8f90&Authority=test-authority&Status=OK');
        $response = $controller($request, $this->app->make(OnlineGatewayService::class));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('پرداخت موفق', $response->getContent());
        $this->assertDatabaseHas('gateway_orders', ['id' => $order->id, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('wallet_transactions', ['status' => 'SUCCESS']);
    }
}
