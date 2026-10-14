<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Crypto\CryptoConfigService;
use App\Services\Crypto\RateService;
use App\Services\Crypto\RateUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The toman rate behind the crypto checkout: Nobitex first (orderbook, then
 * stats, with the rial/toman unit detected from USDT the way the fork did),
 * then a Binance-dollar x USDT-toman proxy, a typed failure when even that
 * is unreachable, and a one-minute cache so one checkout cannot hammer the
 * price APIs.
 */
class CryptoRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private const NOBITEX = 'https://nobitex.test';

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
            'connectix_bot.admin_ids' => ['999'],
        ]);

        Http::preventStrayRequests();
        Cache::flush();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function useNobitexBase(): void
    {
        app(CryptoConfigService::class)->save(['crypto_nobitex_base' => self::NOBITEX]);
    }

    private function service(): RateService
    {
        return app(RateService::class);
    }

    /** The fork's orderbook answer shape. */
    private function orderbook(float $price): array
    {
        return ['lastTradePrice' => (string) $price, 'bids' => [], 'asks' => []];
    }

    // -----------------------------------------------------------------
    // Nobitex
    // -----------------------------------------------------------------

    public function test_it_prices_a_coin_from_the_nobitex_orderbook(): void
    {
        $this->useNobitexBase();

        Http::fake([
            'https://nobitex.test/v3/orderbook/TRXIRT' => Http::response($this->orderbook(8500)),
            'https://nobitex.test/v3/orderbook/USDTIRT' => Http::response($this->orderbook(90000)),
        ]);

        $this->assertSame(8500.0, $this->service()->rateToman('trx'));
    }

    public function test_it_detects_the_rial_unit_from_the_usdt_price(): void
    {
        $this->useNobitexBase();

        // 900,000 for a dollar can only be rial: divide by ten (the fork's
        // unit detector), so a 85,000-rial TRX becomes 8,500 toman.
        Http::fake([
            'https://nobitex.test/v3/orderbook/TRXIRT' => Http::response($this->orderbook(85000)),
            'https://nobitex.test/v3/orderbook/USDTIRT' => Http::response($this->orderbook(900000)),
        ]);

        $this->assertSame(8500.0, $this->service()->rateToman('trx'));
    }

    public function test_it_prices_ton_from_the_nobitex_orderbook(): void
    {
        $this->useNobitexBase();

        Http::fake([
            'https://nobitex.test/v3/orderbook/TONIRT' => Http::response($this->orderbook(210000)),
            'https://nobitex.test/v3/orderbook/USDTIRT' => Http::response($this->orderbook(900000)),
        ]);

        $this->assertSame(21000.0, $this->service()->rateToman('ton'));
    }

    // -----------------------------------------------------------------
    // Fallback & failure
    // -----------------------------------------------------------------

    public function test_it_falls_back_to_binance_times_a_usdt_proxy_when_nobitex_fails(): void
    {
        $this->useNobitexBase();

        Http::fake([
            'https://nobitex.test/*' => Http::response(['error' => 'unavailable'], 500),
            'https://api.tetherland.com/*' => Http::response([
                'data' => ['currencies' => ['USDT' => ['price' => 88000]]],
            ]),
            'https://api.binance.com/*' => fn () => Http::response(['price' => '0.16']),
        ]);

        // 0.16 USD x 88,000 toman.
        $this->assertSame(0.16 * 88000, $this->service()->rateToman('trx'));
    }

    public function test_every_source_failing_raises_a_typed_error(): void
    {
        $this->useNobitexBase();

        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

        $this->expectException(RateUnavailable::class);

        $this->service()->rateToman('trx');
    }

    public function test_a_quote_is_cached_for_a_minute(): void
    {
        $this->useNobitexBase();

        Http::fake([
            'https://nobitex.test/v3/orderbook/TRXIRT' => Http::response($this->orderbook(8500)),
            'https://nobitex.test/v3/orderbook/USDTIRT' => Http::response($this->orderbook(90000)),
        ]);

        $first = $this->service()->rateToman('trx');
        $sentAfterFirst = Http::recorded()->count();

        $second = $this->service()->rateToman('trx');

        $this->assertSame($first, $second);
        $this->assertSame($sentAfterFirst, Http::recorded()->count(), 'the cache must serve the second call');
    }
}
