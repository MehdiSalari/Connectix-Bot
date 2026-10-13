<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The toman price of one TRX or TON.
 *
 * Port of the rate half of the fork's `CryptoPayService::autoRate()`:
 *
 *   1. Nobitex orderbook `<COIN>IRT` (last trade, else the bid/ask middle),
 *      with the rial/toman unit detected from the `USDTIRT` price the same
 *      way the fork did (a dollar above 350,000 can only be rial), falling
 *      back to `market/stats`, which is always rial;
 *   2. when Nobitex cannot answer: Binance `<COIN>USDT` dollars times a
 *      USDT-in-toman proxy (Nobitex first, then Tetherland);
 *   3. when even that fails: {@see RateUnavailable}.
 *
 * A quote is cached for a minute so one checkout cannot hammer the price
 * APIs. Nobitex requires its `TraderBot/<name>` user agent, which is why the
 * headers are pinned here.
 */
class RateService
{
    /** How long one quote is reused. */
    public const CACHE_TTL_SECONDS = 60;

    /** Above this, a USDT price can only be rial (the fork's unit detector). */
    public const RIAL_THRESHOLD = 350000;

    private const ENDPOINTS = [
        'trx' => ['pair' => 'TRXIRT', 'src' => 'trx', 'binance' => 'TRXUSDT'],
        'ton' => ['pair' => 'TONIRT', 'src' => 'ton', 'binance' => 'TONUSDT'],
    ];

    public function __construct(private readonly CryptoConfigService $config) {}

    /**
     * Toman for one coin, cached for {@see self::CACHE_TTL_SECONDS}.
     *
     * @throws RateUnavailable when no source could produce a price
     */
    public function rateToman(string $network): float
    {
        if (! isset(self::ENDPOINTS[$network])) {
            throw new RateUnavailable("Unsupported crypto network [$network].");
        }

        $cached = Cache::get($this->cacheKey($network));

        if (is_numeric($cached) && (float) $cached > 0) {
            return (float) $cached;
        }

        $quote = $this->fetch($network);

        if ($quote === null || $quote['rate'] <= 0) {
            throw new RateUnavailable("No usable $network rate from any source.");
        }

        Cache::put($this->cacheKey($network), $quote['rate'], self::CACHE_TTL_SECONDS);

        return $quote['rate'];
    }

    /**
     * Every source, live and uncached, for the panel's "تست نرخ" style
     * report: which source answered and with what price.
     *
     * @return array<string, array{rate: float|null, source: string|null}>
     */
    public function rateTest(): array
    {
        $out = [];

        foreach (array_keys(self::ENDPOINTS) as $network) {
            $quote = $this->fetch($network);
            $out[$network] = [
                'rate' => $quote['rate'] ?? null,
                'source' => $quote['source'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Nobitex first, then the dollar/tether proxy.
     *
     * @return array{rate: float, source: string}|null
     */
    private function fetch(string $network): ?array
    {
        return $this->fromNobitex($network) ?? $this->fromBinanceProxy($network);
    }

    /**
     * @return array{rate: float, source: string}|null
     */
    private function fromNobitex(string $network): ?array
    {
        $meta = self::ENDPOINTS[$network];
        $base = $this->nobitexBase();

        $coin = $this->orderbookPrice($base, $meta['pair']);

        if ($coin === null) {
            $stats = $this->nobitexStats($base, $meta['src']);

            if ($stats !== null) {
                // market/stats is always rial; the fork divided it by ten.
                return ['rate' => $stats / 10, 'source' => 'nobitex-stats'];
            }

            return null;
        }

        $usdt = $this->orderbookPrice($base, 'USDTIRT');
        $divisor = ($usdt !== null && $usdt > self::RIAL_THRESHOLD) ? 10 : 1;

        return ['rate' => $coin / $divisor, 'source' => 'nobitex'];
    }

    /**
     * Binance dollars times a toman-per-dollar proxy.
     *
     * @return array{rate: float, source: string}|null
     */
    private function fromBinanceProxy(string $network): ?array
    {
        $usdToman = $this->usdtToman();

        if ($usdToman === null) {
            return null;
        }

        $url = 'https://api.binance.com/api/v3/ticker/price?symbol='.self::ENDPOINTS[$network]['binance'];
        $json = $this->get($url, $this->browserHeaders());

        $usd = is_array($json) && is_numeric($json['price'] ?? null) ? (float) $json['price'] : null;

        if ($usd === null || $usd <= 0) {
            return null;
        }

        return ['rate' => $usd * $usdToman, 'source' => 'binance'];
    }

    /**
     * One USDT in toman: Nobitex (unit-detected), then Tetherland.
     */
    private function usdtToman(): ?float
    {
        $raw = $this->orderbookPrice($this->nobitexBase(), 'USDTIRT');

        if ($raw !== null) {
            return $raw > self::RIAL_THRESHOLD ? $raw / 10 : $raw;
        }

        $json = $this->get('https://api.tetherland.com/currencies', $this->browserHeaders());
        $price = is_array($json) ? ($json['data']['currencies']['USDT']['price'] ?? null) : null;

        if (! is_numeric($price) || (float) $price <= 0) {
            return null;
        }

        return (float) $price;
    }

    /**
     * One price out of a Nobitex `/v3/orderbook/<PAIR>` answer: the last
     * trade, else the middle of the best bid and ask.
     */
    private function orderbookPrice(string $base, string $pair): ?float
    {
        $json = $this->nobitexGet($base, '/v3/orderbook/'.$pair);

        if (! is_array($json)) {
            return null;
        }

        $last = $json['lastTradePrice'] ?? null;

        if (is_numeric($last) && (float) $last > 0) {
            return (float) $last;
        }

        $bid = $json['bids'][0][0] ?? null;
        $ask = $json['asks'][0][0] ?? null;

        if (is_numeric($bid) && is_numeric($ask) && (float) $bid > 0 && (float) $ask > 0) {
            return ((float) $bid + (float) $ask) / 2;
        }

        if (is_numeric($ask) && (float) $ask > 0) {
            return (float) $ask;
        }

        if (is_numeric($bid) && (float) $bid > 0) {
            return (float) $bid;
        }

        return null;
    }

    /**
     * `latest` of a Nobitex `market/stats` answer (always rial).
     */
    private function nobitexStats(string $base, string $src): ?float
    {
        $json = $this->nobitexGet($base, '/market/stats?srcCurrency='.$src.'&dstCurrency=rls');
        $value = is_array($json) ? ($json['stats'][$src.'-rls']['latest'] ?? null) : null;

        return (is_numeric($value) && (float) $value > 0) ? (float) $value : null;
    }

    private function nobitexGet(string $base, string $path): ?array
    {
        // A mirror may be a relay like https://host/relay.php?token=X, where
        // the path travels in ?p= — the fork's nbxUrl() rule.
        $url = str_contains($base, '?')
            ? $base.'&p='.rawurlencode($path)
            : rtrim($base, '/').$path;

        $json = $this->get($url, [
            'User-Agent' => 'TraderBot/Airoxnet-1.0',
            'Accept' => 'application/json',
        ]);

        return is_array($json) ? $json : null;
    }

    private function nobitexBase(): string
    {
        $base = trim((string) $this->config->get('crypto_nobitex_base'));

        if ($base === '') {
            $base = 'https://api.nobitex.ir';
        }

        return str_contains($base, '?') ? $base : rtrim($base, '/');
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function get(string $url, array $headers): ?array
    {
        try {
            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->connectTimeout(8)
                ->get($url);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        // Public price APIs behind Cloudflare reject an obvious bot agent.
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
        ];
    }

    private function cacheKey(string $network): string
    {
        return 'crypto.rate.'.$network;
    }
}

/**
 * Thrown when neither Nobitex nor the dollar proxy could price a coin, so
 * callers can tell "no rate right now" apart from a programming error.
 */
class RateUnavailable extends \RuntimeException {}
