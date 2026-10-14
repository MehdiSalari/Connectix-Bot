<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Models\CryptoHash;
use App\Models\CryptoPayment;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The bot side of the TRX/TON payment flow.
 *
 * Port of the feature fork's `CryptoPayService` (the standalone bot file),
 * split from {@see CryptoConfigService} which owns the panel settings. The
 * flow is the fork's:
 *
 *   1. the customer picks a network (`crypto_net:<mode>:<net>`) and an order
 *      is written: our receiving address, the exact coin amount at a rate
 *      locked in toman, and a deadline;
 *   2. after paying, the customer sends the transaction hash — bare or as a
 *      Tronscan/Tonviewer link, {@see self::extractHash()} knows both;
 *   3. {@see self::verify()} looks the hash up on the public blockchain API
 *      and checks that the transaction succeeded, went to OUR address, is not
 *      older than the order, and matches the amount within tolerance. A
 *      fully clean transaction with auto-confirm on completes the order (the
 *      wallet is credited / the payment is settled); anything else goes to
 *      the administrators or back to the customer for another hash (five
 *      tries, and every submitted hash is remembered forever in
 *      `crypto_hashes` so it can never pay twice).
 *
 * Money is always handled in the network's smallest unit (sun / nanoTON) as
 * integers; datetimes are stored as `Y-m-d H:i:s` strings in Asia/Tehran,
 * the same convention the rest of the bot uses.
 */
class CryptoPayService
{
    /** A hash may still be submitted this long after the deadline (paid at the last minute). */
    public const GRACE_SECONDS = 1800;

    /** How many rejected verifications one order gets before it closes. */
    public const MAX_ATTEMPTS = 5;

    /** Accepted amount deviation, in percent (task: ±1.5%; fork default was 1.0). */
    public const TOLERANCE_PERCENT = 1.5;

    /** The `users.action` value that means "the next text is a tx hash". */
    public const ACTION_HASH = 'crypto_hash';

    /** Key inside the user state holding the order the hash belongs to. */
    public const STATE_ORDER_KEY = 'crypto_id';

    public const STATUS_WAITING = 'WAITING';

    public const STATUS_REVIEW = 'REVIEW';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUS_CANCELED = 'CANCELED';

    /**
     * The network metadata, with the fork's display labels and units.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function nets(): array
    {
        return [
            'trx' => [
                'name' => 'ترون (TRX)',
                'symbol' => 'TRX',
                'decimals' => 6,
                'base' => 1000000,
                'chain' => 'Tron (TRC20)',
                'explorer' => 'https://tronscan.org/#/transaction/',
                'api_base' => 'https://api.trongrid.io',
            ],
            'ton' => [
                'name' => 'تون (TON)',
                'symbol' => 'TON',
                'decimals' => 9,
                'base' => 1000000000,
                'chain' => 'TON',
                'explorer' => 'https://tonviewer.com/transaction/',
                'api_base' => 'https://toncenter.com',
            ],
        ];
    }

    /** Explorer hosts a transaction link may come from (the fork's list). */
    private const EXPLORERS = [
        'trx' => ['tronscan.org', 'tronscan.io'],
        'ton' => ['tonviewer.com', 'tonscan.org', 'ton.cx', 'explorer.toncoin.org', 'dton.io', 'tonapi.io', 'toncenter.com'],
    ];

    /** Every bot message of the flow, copied from the fork's `msgDefaults()`. */
    private const MESSAGES = [
        'menu_title' => "💎 <b>پرداخت با ارز دیجیتال</b>\n\nلطفاً شبکهای که میخواهید با آن پرداخت کنید را انتخاب کنید 👇\n\n• مبلغ بر اساس نرخ لحظهای محاسبه و تا پایان مهلت ثابت میماند.\n• پس از واریز، هش یا لینک تراکنش را بفرستید؛ تایید بهصورت خودکار انجام میشود.",
        'order_screen' => "💎 <b>پرداخت با {network}</b>\n\n💵 مبلغ سفارش: <b>{amount}</b> تومان\n📈 نرخ امروز {symbol}: {rate} تومان\n{note}\n🪙 مبلغ دقیق قابل پرداخت (برای کپی، روی عدد بزنید):\n<code>{coins}</code> {symbol}\n\n📬 آدرس کیف پول (برای کپی، روی آدرس بزنید):\n<code>{address}</code>\n\n⏳ مهلت پرداخت تا {deadline}\n\n⚠️ فقط <b>{symbol}</b> را روی شبکه <b>{chain}</b> و به همین آدرس ارسال کنید. مبلغ را دقیقاً مطابق بالا بفرستید (کارمزد صرافی را خودتان اضافه کنید).\n\n✅ بعد از واریز، <b>هش تراکنش</b> یا <b>لینک تراکنش</b> (از Tronscan / Tonviewer) را همینجا ارسال کنید. ربات بررسی میکند که آدرس مقصد و مبلغ درست باشد.\n🔒 هر هش فقط یکبار قابل ثبت است.",
        'rate_updated' => '🕒 نرخ و مبلغ در {time} بهروز شد',
        'ask_hash' => '🔗 هش یا لینک تراکنش را ارسال کنید.',
        'hash_needed_text' => '🔗 لطفاً هش یا لینک تراکنش را بهصورت متن ارسال کنید.',
        'checking' => '🔎 در حال بررسی تراکنش در بلاکچین ...',
        'waiting_chain' => "⏳ تراکنش هنوز در بلاکچین نهایی نشده است.\nمعمولاً چند دقیقه طول میکشد؛ بررسی بهصورت خودکار ادامه دارد یا میتوانید دکمه بررسی مجدد را بزنید.\n🕒 {time}",
        'auto_confirmed' => "✅ پرداخت شما در بلاکچین تایید شد.\n✅ آدرس مقصد با کیف پول ما مطابقت دارد.\n🪙 مبلغ دریافتی: {coins} {symbol}",
        'confirmed_admin_finish' => "✅ پرداخت شما در بلاکچین تایید شد.\n📨 تکمیل سفارش به ادمین سپرده شد و بهزودی انجام میشود.",
        'sent_to_review' => "📨 پرداخت شما برای بررسی به ادمین ارسال شد.\nپس از تایید، نتیجه به شما اطلاع داده میشود.",
        'auto_rejected_retry' => "❌ {reason}{detail}\n\nاین هش ثبت شد و دیگر قابل استفاده نیست. اگر پرداخت دیگری انجام دادهاید، هش آن را ارسال کنید. ({left} تلاش باقی مانده)",
        'auto_rejected_final' => "❌ {reason}{detail}\n\nتعداد تلاشهای مجاز به پایان رسید. لطفاً با پشتیبانی تماس بگیرید.",
        'tx_used' => '❌ این تراکنش قبلاً برای یک سفارش دیگر استفاده شده است.',
        'hash_invalid' => "❌ {error}\n\nهش یا لینک تراکنش را دوباره ارسال کنید.",
        'hash_duplicate' => "❌ این هش قبلاً ثبت شده و دیگر قابل استفاده نیست.\nاگر پرداخت دیگری انجام دادهاید، هش آن را ارسال کنید.",
        'status_confirmed' => '✅ این سفارش قبلاً تایید شده است.',
        'status_review' => '📨 پرداخت شما در حال بررسی توسط ادمین است.',
        'status_rejected' => '❌ این سفارش رد شده است.',
        'status_expired' => '⌛ مهلت این سفارش تمام شده است.',
        'status_canceled' => '🚫 این سفارش لغو شده است.',
        'order_canceled' => '🚫 سفارش پرداخت کریپتو لغو شد.',
        'order_expired_input' => '⌛ مهلت این سفارش تمام شده است. اگر پرداخت کردهاید با پشتیبانی تماس بگیرید، در غیر این صورت سفارش جدید ثبت کنید.',
        'order_inactive' => 'این سفارش دیگر فعال نیست. برای شروع دوباره /start را بزنید.',
        'err_disabled' => '⚠️ پرداخت با ارز دیجیتال در حال حاضر فعال نیست.',
        'err_net_inactive' => '⚠️ این شبکه در حال حاضر فعال نیست.',
        'err_rate' => '⚠️ نرخ ارز در حال حاضر در دسترس نیست. کمی بعد دوباره تلاش کنید.',
        'err_min' => '⚠️ حداقل مبلغ پرداخت با ارز دیجیتال {min} تومان است.',
        'err_not_yours' => '⛔ این سفارش متعلق به شما نیست.',
        'err_order_expired' => '⚠️ اطلاعات سفارش منقضی شده است. لطفاً از ابتدا پلن را انتخاب کنید.',
        'err_wallet_amount' => '⚠️ ابتدا مبلغ افزایش موجودی را وارد کنید.',
        'err_units' => '⚠️ مبلغ محاسبهشده نامعتبر است.',
        'err_wallet_missing' => '⚠️ کیف پول شما پیدا نشد.',
        'admin_result_ok' => "💎 پرداخت کریپتو #{id}\n{user}\n{order}\n💵 {amount} تومان\n\n✅ {message}",
        'admin_result_no' => "💎 پرداخت کریپتو #{id}\n{user}\n{order}\n💵 {amount} تومان\n\n❌ {message}",
        'admin_result_err' => "💎 پرداخت کریپتو #{id}\n{user}\n{order}\n💵 {amount} تومان\n\n⚠️ {message}",
    ];

    public function __construct(
        private readonly CryptoConfigService $config,
        private readonly RateService $rates,
        private readonly WalletService $wallets,
    ) {}

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function isEnabled(): bool
    {
        try {
            if (! (bool) $this->config->get('crypto_enabled')) {
                return false;
            }

            return $this->activeNets() !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The networks a customer can actually pay with: switched on and holding
     * a wallet address.
     *
     * @return array<string, array<string, mixed>>
     */
    public function activeNets(): array
    {
        $stored = $this->config->networks();
        $out = [];

        foreach (self::nets() as $name => $meta) {
            if (! empty($stored[$name]['enabled']) && trim((string) $stored[$name]['address']) !== '') {
                $out[$name] = $meta;
            }
        }

        return $out;
    }

    /**
     * The per-network settings block (address, rate_mode, api_base...).
     *
     * @return array{enabled: bool, address: string, rate_mode: string, rate_toman: float, api_key: string, api_base: string}
     */
    private function networkConfig(string $network): array
    {
        $block = $this->config->networks()[$network] ?? [];

        return [
            'enabled' => (bool) ($block['enabled'] ?? false),
            'address' => trim((string) ($block['address'] ?? '')),
            'rate_mode' => ($block['rate_mode'] ?? '') === 'auto' ? 'auto' : 'manual',
            'rate_toman' => (float) ($block['rate_toman'] ?? 0),
            'api_key' => trim((string) ($block['api_key'] ?? '')),
            'api_base' => trim((string) ($block['api_base'] ?? '')) !== ''
                ? trim((string) $block['api_base'])
                : self::nets()[$network]['api_base'],
        ];
    }

    /**
     * Toman per one coin: the manual rate when the panel pins one, the live
     * {@see RateService} quote otherwise.
     */
    public function rate(string $network): ?float
    {
        $net = $this->networkConfig($network);

        if ($net['rate_mode'] !== 'auto') {
            return $net['rate_toman'] > 0 ? $net['rate_toman'] : null;
        }

        try {
            return $this->rates->rateToman($network);
        } catch (RateUnavailable) {
            return null;
        }
    }

    // -----------------------------------------------------------------
    // Messages / keyboards
    // -----------------------------------------------------------------

    /**
     * A flow message with `{placeholders}` filled in. Values in `$vars` are
     * inserted verbatim, so escape user-controlled content with
     * {@see self::h()} first, exactly like the fork did.
     */
    public function msg(string $key, array $vars = []): string
    {
        $template = self::MESSAGES[$key] ?? '';

        foreach ($vars as $name => $value) {
            $template = str_replace('{'.$name.'}', (string) $value, $template);
        }

        return $template;
    }

    public static function h(string|int|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function button(string $text, string $callback): array
    {
        return ['text' => $text, 'callback_data' => $callback];
    }

    /** @return array<int, array<int, array<string, string>>> */
    private function cancelKb(int $orderId): array
    {
        return [[$this->button('❌ | انصراف', 'crypto_cancel:'.$orderId)]];
    }

    /** @return array<int, array<int, array<string, string>>> */
    private function waitKb(int $orderId): array
    {
        return [
            [$this->button('🔄 | بررسی مجدد', 'crypto_chk:'.$orderId)],
            [$this->button('🏡 | خانه', 'main_menu')],
        ];
    }

    /** @return array<int, array<int, array<string, string>>> */
    private function homeKb(): array
    {
        return [[$this->button('🏡 | خانه', 'main_menu')]];
    }

    // -----------------------------------------------------------------
    // Screens
    // -----------------------------------------------------------------

    /**
     * The network chooser shown by `crypto_menu:<mode>`.
     *
     * @return array{text: string, kb: array<int, array<int, array<string, string>>>}
     */
    public function menuScreen(string $mode = 'buy'): array
    {
        if (! $this->isEnabled()) {
            return ['text' => $this->msg('err_disabled'), 'kb' => $this->homeKb()];
        }

        $rows = [];

        foreach ($this->activeNets() as $name => $meta) {
            $rows[] = [$this->button('🪙 | '.$meta['name'], 'crypto_net:'.$mode.':'.$name)];
        }

        $rows[] = [$this->button('↪️ | بازگشت', $mode === 'wallet' ? 'wallet' : 'main_menu')];

        return ['text' => $this->msg('menu_title'), 'kb' => $rows];
    }

    /**
     * The "send exactly this much to exactly this address" screen.
     *
     * @return array{text: string, kb: array<int, array<int, array<string, string>>>}
     */
    public function orderScreen(CryptoPayment $order, string $note = ''): array
    {
        $meta = self::nets()[$order->network];

        $text = $this->msg('order_screen', [
            'network' => $meta['name'],
            'amount' => number_format((int) $order->amount_toman),
            'symbol' => $meta['symbol'],
            'rate' => number_format((float) $order->rate_toman),
            'note' => $note,
            'coins' => self::fmtUnits((int) $order->expected_units, $order->network),
            'address' => self::h($order->pay_address),
            'deadline' => $this->tehran($this->epoch($order, 'expires_at') ?? time(), 'H:i'),
            'chain' => $meta['chain'],
        ]);

        return [
            'text' => $text,
            'kb' => [
                [$this->button('🔄 | بهروزرسانی نرخ و مبلغ', 'crypto_ref:'.$order->id)],
                [$this->button('❌ | انصراف', 'crypto_cancel:'.$order->id)],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Order creation
    // -----------------------------------------------------------------

    /**
     * Create the order behind `crypto_net:<mode>:<net>`.
     *
     * `$state` is the customer's conversation state: a plan purchase reads
     * `final_price`/`price` (plus `plan`/`acc` for the payment row), a wallet
     * top-up reads `amount`.
     *
     * @param  array<string, mixed>  $state
     * @return array{ok: bool, text: string, order: CryptoPayment|null, kb: array<int, array<int, array<string, string>>>}
     */
    public function createOrder(string $chatId, string $network, string $mode = 'buy', array $state = []): array
    {
        if (! isset(self::nets()[$network]) || ! $this->isEnabled() || ! isset($this->activeNets()[$network])) {
            return $this->fail($this->msg('err_net_inactive'));
        }

        $kind = $mode === 'wallet' ? 'wallet' : 'buy';

        // ---- the toman amount ------------------------------------------------
        if ($kind === 'buy') {
            if (empty($state['plan']) || empty($state['acc'])) {
                return $this->fail($this->msg('err_order_expired'), $this->homeKb());
            }

            $toman = (int) round((float) str_replace(',', '', (string) ($state['final_price'] ?? ($state['price'] ?? 0))));
        } else {
            $toman = (int) ($state['amount'] ?? 0);

            if ($toman <= 0) {
                return $this->fail($this->msg('err_wallet_amount'), [
                    [$this->button('👝 | کیف پول', 'wallet')],
                ]);
            }
        }

        $min = $this->minToman();

        if ($toman < $min) {
            return $this->fail($this->msg('err_min', ['min' => number_format($min)]));
        }

        $rate = $this->rate($network);

        if ($rate === null || $rate <= 0) {
            return $this->fail($this->msg('err_rate'));
        }

        $units = self::toUnits($toman, $rate, $network);

        if ($units <= 0) {
            return $this->fail($this->msg('err_units'));
        }

        $address = $this->networkConfig($network)['address'];

        // One open order per customer: anything still waiting for a hash is
        // cancelled first, exactly like the fork did.
        foreach (CryptoPayment::query()
            ->where('chat_id', (string) $chatId)
            ->where('status', self::STATUS_WAITING)
            ->whereNull('submitted_hash')
            ->get() as $previous) {
            $this->cancelRow($previous);
        }

        // ---- the money-side record -----------------------------------------
        $paymentId = null;
        $walletTxId = null;

        if ($kind === 'buy') {
            $existing = $state['payment_id'] ?? null;

            if ($existing !== null) {
                $paymentId = (int) $existing;
                DB::table('payments')->where('id', $paymentId)
                    ->update(['method' => 'crypto_'.$network]);
            } else {
                $paymentId = DB::table('payments')->insertGetId([
                    'chat_id' => (string) $chatId,
                    'client_id' => ($state['acc'] ?? 'new') === 'new' ? 'new' : (string) $state['acc'],
                    'plan_id' => (string) $state['plan'],
                    'price' => number_format($toman),
                    'coupon' => $state['coupon_code'] ?? null,
                    'is_paid' => null,
                    'method' => 'crypto_'.$network,
                    'created_at' => $this->now(),
                ]);
            }
        } else {
            if ($this->wallets->find((string) $chatId) === null) {
                return $this->fail($this->msg('err_wallet_missing'));
            }

            $deposit = $this->wallets->createPendingDeposit((string) $chatId, $toman);

            if ($deposit === null) {
                return $this->fail($this->msg('err_wallet_missing'));
            }

            $walletTxId = (int) $deposit->id;
        }

        try {
            $order = CryptoPayment::query()->create([
                'chat_id' => (string) $chatId,
                'kind' => $kind,
                'payment_id' => $paymentId,
                'wallet_tx_id' => $walletTxId,
                'network' => $network,
                'pay_address' => $address,
                'amount_toman' => $toman,
                'rate_toman' => (string) $rate,
                'expected_units' => $units,
                'status' => self::STATUS_WAITING,
                'attempts' => 0,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
                'expires_at' => $this->now($this->expiryMinutes() * 60),
            ]);
        } catch (\Throwable) {
            // Do not leave a dangling pending payment/deposit behind.
            $this->rejectPending($chatId, $kind, $paymentId, $walletTxId);

            return $this->fail('⚠️ ثبت سفارش با خطا مواجه شد. دوباره تلاش کنید.', $this->homeKb());
        }

        return ['ok' => true, 'text' => '', 'order' => $order, 'kb' => $this->orderScreen($order)['kb']];
    }

    /**
     * Re-price an order that has not received a hash yet (rate moved, or the
     * customer took long). Port of the fork's `refreshOrder()`.
     *
     * @return array{text: string, kb: array<int, array<int, array<string, string>>>}
     */
    public function refreshOrder(int $orderId, string $chatId): array
    {
        $order = $this->ownedOrder($orderId, $chatId);

        if ($order === null) {
            return ['text' => $this->msg('err_not_yours'), 'kb' => $this->homeKb()];
        }

        if ($order->status !== self::STATUS_WAITING || $order->submitted_hash !== null) {
            return ['text' => $this->statusText($order), 'kb' => $this->homeKb()];
        }

        $rate = $this->rate($order->network);

        if ($rate === null || $rate <= 0) {
            return ['text' => $this->msg('err_rate'), 'kb' => $this->cancelKb($order->id)];
        }

        $order->forceFill([
            'rate_toman' => (string) $rate,
            'expected_units' => self::toUnits((int) $order->amount_toman, $rate, $order->network),
            'expires_at' => $this->now($this->expiryMinutes() * 60),
            'updated_at' => $this->now(),
        ])->save();

        return $this->orderScreen($order->refresh(), $this->msg('rate_updated', ['time' => $this->tehran(time())])."\n");
    }

    /**
     * Cancel an order that never received a hash. Port of `cancelOrder()`.
     *
     * @return array{text: string, kb: array<int, array<int, array<string, string>>>}
     */
    public function cancelOrder(int $orderId, string $chatId): array
    {
        $order = $this->ownedOrder($orderId, $chatId);

        if ($order === null) {
            return ['text' => $this->msg('err_not_yours'), 'kb' => $this->homeKb()];
        }

        if ($this->cancelRow($order)) {
            return ['text' => $this->msg('order_canceled'), 'kb' => $this->homeKb()];
        }

        return ['text' => $this->statusText($order), 'kb' => $this->homeKb()];
    }

    // -----------------------------------------------------------------
    // Hash submission
    // -----------------------------------------------------------------

    /**
     * The customer sent a message while an order waits for its hash. Port of
     * `handleUserInput()`.
     *
     * @return array{status: string, text: string, kb: array<int, array<int, array<string, string>>>}
     */
    public function submitHash(string $chatId, ?string $text, ?int $orderId): array
    {
        $order = $orderId !== null ? CryptoPayment::query()->find($orderId) : null;

        if ($order === null || (string) $order->chat_id !== (string) $chatId) {
            return $this->res('missing', $this->msg('order_inactive'), $this->homeKb());
        }

        if ($order->status !== self::STATUS_WAITING) {
            return $this->res($order->status, $this->statusText($order), $this->homeKb());
        }

        // A hash is already under verification: re-check instead of taking a
        // new one, which is what the fork's WAITING branch did.
        if ($order->submitted_hash !== null) {
            return $this->verify((int) $order->id);
        }

        if ($this->epoch($order, 'expires_at') !== null
            && time() > $this->epoch($order, 'expires_at') + self::GRACE_SECONDS) {
            $this->expireRow($order);

            return $this->res(self::STATUS_EXPIRED, $this->msg('order_expired_input'), $this->homeKb());
        }

        if ((int) $order->attempts >= self::MAX_ATTEMPTS) {
            $order->forceFill([
                'status' => self::STATUS_REJECTED,
                'review_reason' => 'تعداد تلاشها به پایان رسید',
                'updated_at' => $this->now(),
            ])->save();

            return $this->res(self::STATUS_REJECTED, $this->msg('auto_rejected_final', [
                'reason' => 'تعداد تلاشهای مجاز به پایان رسید',
                'detail' => '',
            ]), $this->homeKb());
        }

        if ($text === null || trim($text) === '') {
            return $this->res(self::STATUS_WAITING, $this->msg('hash_needed_text'), $this->cancelKb((int) $order->id));
        }

        $extracted = $this->extractHash($text, $order->network);

        if (! $extracted['ok']) {
            return $this->res(self::STATUS_WAITING, $this->msg('hash_invalid', ['error' => self::h($extracted['error'])]), $this->cancelKb((int) $order->id));
        }

        // The hash is remembered the moment it is sent — whatever the result,
        // it can never be sent again.
        if (! $this->burnHash($order->network, $extracted['hash'], (int) $order->id, (string) $order->chat_id)) {
            return $this->res(self::STATUS_WAITING, $this->msg('hash_duplicate'), $this->cancelKb((int) $order->id));
        }

        $order->forceFill([
            'submitted_hash' => $extracted['hash'],
            'status' => self::STATUS_WAITING,
            'submitted_at' => $this->now(),
            'updated_at' => $this->now(),
        ])->save();

        $result = $this->verify((int) $order->id);

        return $result['text'] !== ''
            ? $result
            : $this->res(self::STATUS_WAITING, $this->msg('checking'), $this->waitKb((int) $order->id));
    }

    // -----------------------------------------------------------------
    // Verification
    // -----------------------------------------------------------------

    /**
     * Re-check an order against the chain and act on the result. Port of
     * `verify()`.
     *
     * @return array{status: string, text: string, kb: array<int, array<int, array<string, string>>>}
     */
    public function verify(int $orderId): array
    {
        $order = CryptoPayment::query()->find($orderId);

        if ($order === null) {
            return $this->res('missing', '⚠️ سفارش پیدا نشد.');
        }

        if ($order->status !== self::STATUS_WAITING) {
            return $this->res($order->status, $this->statusText($order));
        }

        if ($order->submitted_hash === null || $order->submitted_hash === '') {
            return $this->res(self::STATUS_WAITING, $this->msg('ask_hash'), $this->cancelKb((int) $order->id));
        }

        $order->forceFill(['last_check_at' => $this->now()])->save();

        $chain = $order->network === 'trx' ? $this->fetchTron($order) : $this->fetchTon($order);
        $decision = $this->evaluate($order, $chain);

        switch ($decision['decision']) {
            case 'wait':
                return $this->res(self::STATUS_WAITING, $this->msg('waiting_chain', ['time' => $this->tehran(time())]), $this->waitKb((int) $order->id));

            case 'reject':
                return $this->handleReject($order, $decision);

            case 'review':
                return $this->toReview($order, $decision, $decision['reason']);

            case 'confirm':
                return $this->complete($order, $decision);
        }

        return $this->res($order->status, $this->statusText($order));
    }

    /**
     * A rejected verification: count the attempt, then either close the order
     * or send the customer back for another hash.
     *
     * @param  array<string, mixed>  $decision
     * @return array{status: string, text: string, kb: array<int, array<int, array<string, string>>>}
     */
    private function handleReject(CryptoPayment $order, array $decision): array
    {
        $attempts = (int) $order->attempts + 1;
        $reason = (string) $decision['reason'];
        $detail = (string) ($decision['detail'] ?? '');

        if ($attempts >= self::MAX_ATTEMPTS) {
            $order->forceFill([
                'status' => self::STATUS_REJECTED,
                'attempts' => $attempts,
                'review_reason' => $reason,
                'updated_at' => $this->now(),
            ])->save();

            return $this->res(self::STATUS_REJECTED, $this->msg('auto_rejected_final', [
                'reason' => self::h($reason),
                'detail' => $detail,
            ]), $this->homeKb());
        }

        $order->forceFill([
            'status' => self::STATUS_WAITING,
            'submitted_hash' => null,
            'attempts' => $attempts,
            'review_reason' => $reason,
            'updated_at' => $this->now(),
        ])->save();

        return $this->res(self::STATUS_WAITING, $this->msg('auto_rejected_retry', [
            'reason' => self::h($reason),
            'detail' => $detail,
            'left' => self::MAX_ATTEMPTS - $attempts,
        ]), $this->cancelKb((int) $order->id));
    }

    /**
     * Send a payment that needs a human to the administrators.
     *
     * @param  array<string, mixed>  $decision
     * @return array{status: string, text: string, kb: array<int, array<int, array<string, string>>>}
     */
    private function toReview(CryptoPayment $order, array $decision, string $reason): array
    {
        $txKey = (string) ($decision['tx_key'] ?? '');

        if ($txKey !== '' && ! $this->claimTx($order, $txKey)) {
            $order->forceFill(['submitted_hash' => null, 'updated_at' => $this->now()])->save();

            return $this->res(self::STATUS_WAITING, $this->msg('tx_used'), $this->cancelKb((int) $order->id));
        }

        $order->forceFill([
            'status' => self::STATUS_REVIEW,
            'received_units' => (int) ($decision['units'] ?? 0),
            'sender_address' => (string) ($decision['from'] ?? ''),
            'review_reason' => $reason,
            'updated_at' => $this->now(),
        ])->save();

        return $this->res(self::STATUS_REVIEW, $this->msg('sent_to_review'), $this->homeKb());
    }

    /**
     * A clean transaction: burn the hash, mark the order done, settle it.
     *
     * @param  array<string, mixed>  $decision
     * @return array{status: string, text: string, kb: array<int, array<int, array<string, string>>>}
     */
    private function complete(CryptoPayment $order, array $decision): array
    {
        if (! $this->claimTx($order, (string) $decision['tx_key'])) {
            $order->forceFill(['submitted_hash' => null, 'updated_at' => $this->now()])->save();

            return $this->res(self::STATUS_WAITING, $this->msg('tx_used'), $this->cancelKb((int) $order->id));
        }

        $order->forceFill([
            'status' => self::STATUS_COMPLETED,
            'received_units' => (int) $decision['units'],
            'sender_address' => (string) ($decision['from'] ?? ''),
            'review_reason' => 'auto',
            'updated_at' => $this->now(),
        ])->save();

        if (! $this->finalize($order)) {
            return $this->res(self::STATUS_REVIEW, $this->msg('confirmed_admin_finish'), $this->homeKb());
        }

        return $this->res(self::STATUS_COMPLETED, $this->msg('auto_confirmed', [
            'coins' => self::fmtUnits((int) $decision['units'], $order->network),
            'symbol' => self::nets()[$order->network]['symbol'],
        ]), $this->homeKb());
    }

    /**
     * Decide what a chain answer means for an order. Port of `evaluate()`.
     *
     * @param  array<string, mixed>  $chain
     * @return array<string, mixed>
     */
    private function evaluate(CryptoPayment $order, array $chain): array
    {
        $network = $order->network;

        if (($chain['state'] ?? 'error') !== 'ok') {
            return [
                'decision' => 'wait',
                'reason' => ($chain['state'] ?? '') === 'notfound'
                    ? 'تراکنش هنوز در بلاکچین پیدا یا نهایی نشده است'
                    : 'پاسخی از سرویس بلاکچین دریافت نشد',
            ];
        }

        $details = [
            'units' => (int) ($chain['units'] ?? 0),
            'from' => (string) ($chain['from'] ?? ''),
            'tx_key' => (string) ($chain['tx_key'] ?? ''),
            'time' => $chain['time'] ?? null,
        ];

        if (empty($chain['success'])) {
            return $details + ['decision' => 'reject', 'reason' => 'این تراکنش در بلاکچین ناموفق بوده است'];
        }

        if ($network === 'trx' && empty($chain['type_ok'])) {
            return $details + ['decision' => 'reject', 'reason' => 'این تراکنش انتقال TRX نیست (فقط TRX پذیرفته میشود، نه توکنهایی مثل USDT)'];
        }

        // The wallet the money went to MUST be the one the admin configured.
        $ours = $network === 'trx'
            ? $this->networkConfig('trx')['address']
            : self::tonParseAddress($this->networkConfig('ton')['address']);
        $theirs = $network === 'trx'
            ? ($chain['to'] ?? null)
            : self::tonParseAddress((string) ($chain['to'] ?? ''));

        if ($theirs === null || $theirs === '' || $theirs !== $ours) {
            return $details + [
                'decision' => 'reject',
                'reason' => 'آدرس مقصد این تراکنش، آدرس کیف پول ما نیست',
                'detail' => "\n\n📤 آدرس مقصد در تراکنش شما:\n<code>".self::h($chain['to'] ?? 'نامشخص')."</code>\n📬 آدرس صحیح ما:\n<code>".self::h($this->networkConfig($network)['address']).'</code>',
            ];
        }

        if (! empty($details['time'])) {
            $created = $this->epoch($order, 'created_at');

            if ($created !== null && (int) $details['time'] < $created - 120) {
                return $details + ['decision' => 'reject', 'reason' => 'این تراکنش قبل از ثبت سفارش انجام شده است'];
            }

            $deadline = $this->epoch($order, 'expires_at');

            if ($deadline !== null && (int) $details['time'] > $deadline + self::GRACE_SECONDS) {
                return $details + ['decision' => 'review', 'reason' => 'پرداخت پس از پایان مهلت سفارش انجام شده است'];
            }
        }

        $expected = (int) $order->expected_units;
        $tolerance = self::TOLERANCE_PERCENT / 100;
        $lower = (int) floor($expected * (1 - $tolerance));
        $upper = (int) ceil($expected * (1 + $tolerance));

        if ($details['units'] < $lower || $details['units'] > $upper) {
            return $details + [
                'decision' => 'review',
                'reason' => $details['units'] < $lower
                    ? 'مبلغ واریزی کمتر از مبلغ سفارش است'
                    : 'مبلغ واریزی بیشتر از مبلغ سفارش است',
            ];
        }

        if (! (bool) $this->config->get('crypto_auto_confirm')) {
            return $details + ['decision' => 'review', 'reason' => 'تایید خودکار خاموش است (مبلغ و آدرس صحیح است)'];
        }

        return $details + ['decision' => 'confirm', 'reason' => 'ok'];
    }

    // -----------------------------------------------------------------
    // Admin decision
    // -----------------------------------------------------------------

    /**
     * Confirm or reject an order by hand (bot button or panel). Port of
     * `adminDecide()`.
     *
     * @return array{ok: bool, message: string}
     */
    public function adminDecide(int $orderId, bool $approve, string $by = ''): array
    {
        $order = CryptoPayment::query()->find($orderId);

        if ($order === null) {
            return ['ok' => false, 'message' => 'سفارش پیدا نشد.'];
        }

        $open = [self::STATUS_REVIEW, self::STATUS_WAITING];

        if (! in_array($order->status, $open, true)) {
            return ['ok' => false, 'message' => 'این سفارش قبلاً بررسی شده است ('.$this->statusLabel($order->status).').'];
        }

        if ($approve) {
            if (blank($order->tx_hash) && blank($order->submitted_hash)) {
                return ['ok' => false, 'message' => 'برای این سفارش هنوز هشی ثبت نشده است.'];
            }

            $order->forceFill([
                'status' => self::STATUS_COMPLETED,
                'review_reason' => trim((string) $order->review_reason.' | تایید دستی '.$by),
                'updated_at' => $this->now(),
            ])->save();

            if (! $this->finalize($order)) {
                return ['ok' => false, 'message' => 'تکمیل سفارش با خطا مواجه شد و سفارش به حالت «در انتظار بررسی» برگشت؛ لاگ را بررسی کنید و دوباره تایید بزنید.'];
            }

            return ['ok' => true, 'message' => 'تایید شد و سفارش تکمیل شد.'];
        }

        $order->forceFill([
            'status' => self::STATUS_REJECTED,
            'review_reason' => trim((string) $order->review_reason.' | رد دستی '.$by),
            'updated_at' => $this->now(),
        ])->save();

        $this->rejectPending((string) $order->chat_id, $order->kind, $order->payment_id, $order->wallet_tx_id, true);

        return ['ok' => true, 'message' => 'رد شد و به کاربر اطلاع داده شد.'];
    }

    // -----------------------------------------------------------------
    // Finalize / reject (money side)
    // -----------------------------------------------------------------

    /**
     * Deliver what the customer paid for.
     *
     * A wallet order credits the balance through the pending deposit that was
     * opened when the order was created; a purchase keeps its `payments` row
     * pending with the crypto method, ready for the admin provisioning flow.
     */
    private function finalize(CryptoPayment $order): bool
    {
        try {
            if ($order->kind === 'wallet') {
                if ($order->wallet_tx_id !== null) {
                    $wallet = $this->wallets->approveDeposit((int) $order->wallet_tx_id);

                    if ($wallet === null) {
                        throw new \RuntimeException('شارژ کیف پول انجام نشد.');
                    }

                    return true;
                }

                $wallet = $this->wallets->increase(
                    (string) $order->chat_id,
                    (int) $order->amount_toman,
                    WalletTransactionType::CardToCard,
                    WalletTransactionStatus::Success,
                );

                if ($wallet === null) {
                    throw new \RuntimeException('شارژ کیف پول انجام نشد.');
                }

                return true;
            }

            if ($order->payment_id !== null) {
                DB::table('payments')->where('id', (int) $order->payment_id)->update([
                    'method' => 'crypto_'.$order->network,
                ]);

                return true;
            }

            // No payment row was linked at creation: leave a pending one the
            // admin flow can settle.
            DB::table('payments')->insert([
                'chat_id' => (string) $order->chat_id,
                'client_id' => 'new',
                'plan_id' => (string) $order->kind,
                'price' => number_format((int) $order->amount_toman),
                'is_paid' => null,
                'method' => 'crypto_'.$order->network,
                'created_at' => $this->now(),
            ]);

            return true;
        } catch (\Throwable) {
            // Money is on-chain but the order is not done: hand it back to
            // the admins (the fork's auto_fail path).
            $order->forceFill([
                'status' => self::STATUS_REVIEW,
                'review_reason' => 'پرداخت درست است ولی تکمیل سفارش خطا داد — پس از رفع مشکل دوباره «تایید» را بزنید',
                'updated_at' => $this->now(),
            ])->save();

            return false;
        }
    }

    /**
     * Throw away the money-side record of an order that will not happen: a
     * pending wallet deposit or an undecided payment stay pending no more.
     */
    private function rejectPending(string $chatId, string $kind, ?int $paymentId, ?int $walletTxId, bool $byAdmin = false): void
    {
        try {
            if ($kind === 'wallet') {
                if ($walletTxId !== null) {
                    $byAdmin
                        ? $this->wallets->rejectDeposit($walletTxId)
                        : $this->wallets->setTransactionStatus($walletTxId, WalletTransactionStatus::CanceledByUser);
                }

                return;
            }

            if ($paymentId !== null) {
                DB::table('payments')->where('id', (int) $paymentId)
                    ->whereNull('is_paid')
                    ->update(['is_paid' => '0']);
            }
        } catch (\Throwable) {
            // A cleanup failure must never break the reply the customer gets.
        }
    }

    /** @return bool whether the order actually was cancelled */
    private function cancelRow(CryptoPayment $order): bool
    {
        if ($order->status !== self::STATUS_WAITING) {
            return false;
        }

        $order->forceFill([
            'status' => self::STATUS_CANCELED,
            'updated_at' => $this->now(),
        ])->save();

        $this->rejectPending((string) $order->chat_id, $order->kind, $order->payment_id, $order->wallet_tx_id);

        return true;
    }

    /** @return bool whether the order actually expired */
    private function expireRow(CryptoPayment $order): bool
    {
        if ($order->status !== self::STATUS_WAITING) {
            return false;
        }

        $order->forceFill([
            'status' => self::STATUS_EXPIRED,
            'updated_at' => $this->now(),
        ])->save();

        $this->rejectPending((string) $order->chat_id, $order->kind, $order->payment_id, $order->wallet_tx_id);

        return true;
    }

    // -----------------------------------------------------------------
    // Hash bookkeeping
    // -----------------------------------------------------------------

    /**
     * Remember a hash forever. False when the same network+hash already
     * belongs to another order — it can never be used twice.
     */
    private function burnHash(string $network, string $hash, int $orderId, string $chatId): bool
    {
        try {
            CryptoHash::query()->create([
                'network' => $network,
                'hash' => $hash,
                'order_id' => $orderId,
                'chat_id' => (string) $chatId,
                'created_at' => $this->now(),
            ]);

            return true;
        } catch (QueryException) {
            $existing = CryptoHash::query()
                ->where('network', $network)
                ->where('hash', $hash)
                ->first();

            return $existing !== null && (int) $existing->order_id === $orderId;
        }
    }

    /** Claim a verified transaction for this order, or fail when it is taken. */
    private function claimTx(CryptoPayment $order, string $key): bool
    {
        if ($key === '') {
            return false;
        }

        if ((string) $order->tx_hash === $key) {
            return true;
        }

        if (! $this->burnHash($order->network, $key, (int) $order->id, (string) $order->chat_id)) {
            return false;
        }

        try {
            $order->forceFill(['tx_hash' => $key, 'updated_at' => $this->now()])->save();

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    // -----------------------------------------------------------------
    // Chain lookups
    // -----------------------------------------------------------------

    /**
     * Look a TRX transaction up on the network's own API. Port of
     * `fetchTron()`; `state` is `ok | notfound | error`.
     *
     * @return array<string, mixed>
     */
    public function fetchTron(CryptoPayment $order): array
    {
        $net = $this->networkConfig('trx');
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];

        if ($net['api_key'] !== '') {
            $headers['TRON-PRO-API-KEY'] = $net['api_key'];
        }

        try {
            // walletsolidity returns only irreversible (confirmed) transactions.
            $response = Http::withHeaders($headers)
                ->timeout(20)
                ->post(rtrim($net['api_base'], '/').'/walletsolidity/gettransactionbyid', [
                    'value' => (string) $order->submitted_hash,
                ]);
        } catch (ConnectionException) {
            return ['state' => 'error', 'error' => 'connection failed'];
        }

        if (! $response->successful()) {
            return ['state' => 'error', 'error' => 'HTTP '.$response->status()];
        }

        $json = $response->json();

        if (! is_array($json) || (empty($json['txID']) && empty($json['raw_data']))) {
            return ['state' => 'notfound'];
        }

        $contract = $json['raw_data']['contract'][0] ?? [];
        $value = $contract['parameter']['value'] ?? [];

        return [
            'state' => 'ok',
            'success' => (($json['ret'][0]['contractRet'] ?? '') === 'SUCCESS'),
            'type_ok' => (($contract['type'] ?? '') === 'TransferContract'),
            'to' => isset($value['to_address']) ? self::tronHexToAddress((string) $value['to_address']) : null,
            'from' => isset($value['owner_address']) ? self::tronHexToAddress((string) $value['owner_address']) : null,
            'units' => (int) ($value['amount'] ?? 0),
            'time' => isset($json['raw_data']['timestamp']) ? (int) floor(((float) $json['raw_data']['timestamp']) / 1000) : null,
            'tx_key' => strtolower((string) ($json['txID'] ?? (string) $order->submitted_hash)),
        ];
    }

    /**
     * Look a TON transaction up on toncenter. Port of `fetchTon()`.
     *
     * @return array<string, mixed>
     */
    public function fetchTon(CryptoPayment $order): array
    {
        $net = $this->networkConfig('ton');
        $headers = $net['api_key'] !== '' ? ['X-API-Key' => $net['api_key']] : [];
        $base = rtrim($net['api_base'], '/');
        $hash = (string) $order->submitted_hash;

        try {
            $response = Http::withHeaders($headers)
                ->timeout(20)
                ->get($base.'/api/v3/transactions?limit=1&offset=0&hash='.rawurlencode($hash));
        } catch (ConnectionException) {
            return ['state' => 'error', 'error' => 'connection failed'];
        }

        if (! $response->successful() && $response->status() !== 404) {
            return ['state' => 'error', 'error' => 'HTTP '.$response->status()];
        }

        $transactions = $response->successful() ? ($response->json()['transactions'] ?? []) : [];

        if (! is_array($transactions) || $transactions === []) {
            return ['state' => 'notfound'];
        }

        $ours = self::tonParseAddress($this->networkConfig('ton')['address']);
        $best = null;

        foreach ($transactions as $tx) {
            $aborted = ! empty($tx['description']['aborted']);
            $candidates = [];
            $incoming = $tx['in_msg'] ?? null;

            if (is_array($incoming) && ! empty($incoming['source'])) {
                $candidates[] = ['via' => 'in', 'msg' => $incoming];
            }

            foreach (($tx['out_msgs'] ?? []) as $outgoing) {
                if (is_array($outgoing) && ! empty($outgoing['destination'])) {
                    $candidates[] = ['via' => 'out', 'msg' => $outgoing];
                }
            }

            foreach ($candidates as $candidate) {
                $message = $candidate['msg'];
                $row = [
                    'state' => 'ok',
                    'success' => ! $aborted,
                    'to' => self::tonParseAddress((string) ($message['destination'] ?? '')),
                    'from' => (string) ($message['source'] ?? ''),
                    'units' => (int) ($message['value'] ?? 0),
                    'time' => isset($tx['now']) ? (int) $tx['now'] : null,
                    'tx_key' => self::tonNormalizeHash((string) ($message['hash'] ?? ''))
                        ?? self::tonNormalizeHash((string) ($tx['hash'] ?? ''))
                        ?? $hash,
                    'via' => $candidate['via'],
                    'msg_hash' => (string) ($message['hash'] ?? ''),
                ];

                if ($row['to'] === $ours) {
                    $best = $row;
                    break 2;
                }

                $best ??= $row;
            }
        }

        if ($best === null) {
            return ['state' => 'ok', 'success' => true, 'to' => null, 'from' => '', 'units' => 0, 'time' => null, 'tx_key' => $hash];
        }

        // A sender-side hash must still have landed: check the receiving side
        // for a bounce / failure.
        if ($best['via'] === 'out' && $best['to'] === $ours && $best['success'] && $best['msg_hash'] !== '') {
            try {
                $inbound = Http::withHeaders($headers)
                    ->timeout(20)
                    ->get($base.'/api/v3/transactionsByMessage?limit=1&offset=0&direction=in&msg_hash='.rawurlencode($best['msg_hash']));
            } catch (ConnectionException) {
                return ['state' => 'error', 'error' => 'connection failed'];
            }

            if (! $inbound->successful()) {
                return ['state' => 'error', 'error' => 'HTTP '.$inbound->status()];
            }

            $landed = $inbound->json()['transactions'][0] ?? null;

            if (! is_array($landed)) {
                return ['state' => 'notfound'];
            }

            if (! empty($landed['description']['aborted'])) {
                $best['success'] = false;
            }
        }

        return $best;
    }

    // -----------------------------------------------------------------
    // Hash / address codecs (verbatim ports of the fork's helpers)
    // -----------------------------------------------------------------

    /**
     * Pull a transaction hash out of what the customer sent: a bare hash or
     * a link to a KNOWN explorer that contains it.
     *
     * Strict on purpose: any link to a host that is not a known Tron or TON
     * explorer rejects the whole message (also look-alikes such as
     * tronscan.org.evil.com), only http(s) on the default port with no
     * user-info is accepted, and more than one different hash in one message
     * is refused rather than guessed.
     *
     * @return array{ok: bool, hash: string|null, error: string|null}
     */
    public function extractHash(string $text, string $network): array
    {
        $fail = static fn (string $error): array => ['ok' => false, 'hash' => null, 'error' => $error];

        $text = trim($text);
        $text = strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        if ($text === '') {
            return $fail('متن خالی است.');
        }

        if (mb_strlen($text) > 1500) {
            return $fail('پیام خیلی طولانی است. فقط هش یا لینک تراکنش را ارسال کنید.');
        }

        // ---- 1) every domain-looking token must be a known explorer ------
        $scopes = [];
        $tokens = preg_split('/[\s\x{200c}\x{200f}\x{200e}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $token = trim($token, " \t\n\r\0\x0B.,;:!?)(\"'«»<>[]{}،؛");

            if ($token === '') {
                continue;
            }

            $hasScheme = (bool) preg_match('~^[a-z][a-z0-9+.\-]*://~i', $token);
            $looksLikeDomain = (bool) preg_match('~^(?:www\.)?[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)*\.[a-z]{2,24}(?::\d+)?(?:[/?#@].*)?$~i', $token);
            $hasSlash = str_contains($token, '/') && self::tonNormalizeHash($token) === null;

            if (! $hasScheme && ! $looksLikeDomain && ! $hasSlash) {
                continue; // a plain word or a bare hash
            }

            $parts = parse_url($hasScheme ? $token : 'https://'.$token);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));

            if (! in_array($scheme, ['http', 'https'], true)
                || $host === ''
                || isset($parts['user'])
                || isset($parts['pass'])
                || (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true))) {
                return $fail('لینک نامعتبر یا ناشناس است. فقط لینک Tronscan یا Tonviewer پذیرفته میشود.');
            }

            $linkNetwork = self::explorerNetwork($host);

            if ($linkNetwork === null) {
                return $fail('لینک ناشناس پذیرفته نمیشود. فقط لینک تراکنش از Tronscan (ترون) یا Tonviewer (تون) را بفرستید، یا فقط خود هش را.');
            }

            if ($linkNetwork !== $network) {
                return $fail('این لینک مربوط به شبکه '.self::nets()[$linkNetwork]['name'].' است، ولی سفارش شما '.self::nets()[$network]['name'].' میباشد.');
            }

            $scopes[] = ($parts['path'] ?? '').' '.($parts['fragment'] ?? '').' '.($parts['query'] ?? '');
        }

        if ($scopes === []) {
            $scopes[] = $text; // no link: the whole message is the scope
        }

        // ---- 2) collect hash candidates ---------------------------------
        $found = [];

        foreach ($scopes as $scope) {
            $isLink = $scope !== $text;

            if (preg_match_all('/(?<![0-9a-fA-F])[0-9a-fA-F]{64}(?![0-9a-fA-F])/', $scope, $matches)) {
                foreach ($matches[0] as $hash) {
                    $found[strtolower($hash)] = true;
                }
            } elseif ($network === 'ton') {
                $pattern = $isLink
                    ? '/(?<![A-Za-z0-9_\-])[A-Za-z0-9_\-]{43}={0,1}(?![A-Za-z0-9_\-=\])/'
                    : '/(?<![A-Za-z0-9_\-\+\/])[A-Za-z0-9_\-\+\/]{43}={0,1}(?![A-Za-z0-9_\-\+\/=])/';

                if (preg_match_all($pattern, $scope, $matches)) {
                    foreach ($matches[0] as $candidate) {
                        $normalized = self::tonNormalizeHash($candidate);

                        if ($normalized !== null) {
                            $found[$normalized] = true;
                        }
                    }
                }
            }
        }

        if ($found === []) {
            return $fail('هش تراکنش در پیام شما پیدا نشد. هش باید ۶۴ کاراکتر (اعداد و حروف a-f) باشد.');
        }

        if (count($found) > 1) {
            return $fail('در پیام شما بیش از یک هش وجود دارد. فقط هش (یا لینک) همان تراکنش را بفرستید.');
        }

        return ['ok' => true, 'hash' => array_key_first($found), 'error' => null];
    }

    /** Which network an explorer host belongs to, or null for unknown hosts. */
    public static function explorerNetwork(string $host): ?string
    {
        $host = strtolower(rtrim($host, '.'));

        foreach (self::EXPLORERS as $network => $domains) {
            foreach ($domains as $domain) {
                if ($host === $domain || (strlen($host) > strlen($domain) && substr($host, -strlen($domain) - 1) === '.'.$domain)) {
                    return $network;
                }
            }
        }

        return null;
    }

    private const B58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function b58decode(string $input): ?string
    {
        $bytes = [];
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $carry = strpos(self::B58, $input[$i]);

            if ($carry === false) {
                return null;
            }

            for ($j = 0, $n = count($bytes); $j < $n; $j++) {
                $carry += $bytes[$j] * 58;
                $bytes[$j] = $carry & 0xFF;
                $carry >>= 8;
            }

            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry >>= 8;
            }
        }

        $zeros = 0;

        while ($zeros < $length && $input[$zeros] === '1') {
            $zeros++;
        }

        $out = str_repeat("\0", $zeros);

        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            $out .= chr($bytes[$i]);
        }

        return $out;
    }

    public static function b58encode(string $binary): string
    {
        $digits = [];
        $length = strlen($binary);

        for ($i = 0; $i < $length; $i++) {
            $carry = ord($binary[$i]);

            for ($j = 0, $n = count($digits); $j < $n; $j++) {
                $carry += $digits[$j] << 8;
                $digits[$j] = $carry % 58;
                $carry = intdiv($carry, 58);
            }

            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
        }

        $zeros = 0;

        while ($zeros < $length && $binary[$zeros] === "\0") {
            $zeros++;
        }

        $out = str_repeat('1', $zeros);

        for ($i = count($digits) - 1; $i >= 0; $i--) {
            $out .= self::B58[$digits[$i]];
        }

        return $out;
    }

    /** Base58Check Tron address (T...) -> lowercase hex "41...", or null. */
    public static function tronAddressToHex(string $address): ?string
    {
        $address = trim($address);

        if (! preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            return null;
        }

        $binary = self::b58decode($address);

        if ($binary === null || strlen($binary) !== 25 || $binary[0] !== "\x41") {
            return null;
        }

        $payload = substr($binary, 0, 21);

        if (substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4) !== substr($binary, 21)) {
            return null;
        }

        return bin2hex($payload);
    }

    /** Lowercase hex "41..." -> base58check Tron address, or null. */
    public static function tronHexToAddress(string $hex): ?string
    {
        $hex = strtolower(preg_replace('/^0x/i', '', trim($hex)));

        if (strlen($hex) === 40) {
            $hex = '41'.$hex;
        }

        if (! preg_match('/^41[0-9a-f]{40}$/', $hex)) {
            return null;
        }

        $payload = hex2bin($hex);

        return self::b58encode($payload.substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4));
    }

    private static function crc16xmodem(string $data): int
    {
        $crc = 0;
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return $crc;
    }

    /** TON address (raw "0:hex" or friendly EQ/UQ...) -> canonical "wc:hex". */
    public static function tonParseAddress(string $address): ?string
    {
        $address = trim($address);

        if ($address === '') {
            return null;
        }

        if (preg_match('/^(-?\d+):([0-9a-fA-F]{64})$/', $address, $matches)) {
            return $matches[1].':'.strtolower($matches[2]);
        }

        if (! preg_match('/^[A-Za-z0-9_\-+\/]{48}$/', $address)) {
            return null;
        }

        $raw = base64_decode(strtr($address, '-_', '+/'), true);

        if ($raw === false || strlen($raw) !== 36) {
            return null;
        }

        $crc = unpack('n', substr($raw, 34, 2))[1];

        if (self::crc16xmodem(substr($raw, 0, 34)) !== $crc) {
            return null;
        }

        $workchain = unpack('c', $raw[1])[1];

        return $workchain.':'.bin2hex(substr($raw, 2, 32));
    }

    /** TON hash (hex / base64 / base64url) -> 64-char lowercase hex. */
    public static function tonNormalizeHash(string $hash): ?string
    {
        $hash = trim($hash);

        if (preg_match('/^[0-9a-fA-F]{64}$/', $hash)) {
            return strtolower($hash);
        }

        if (! preg_match('/^[A-Za-z0-9_\-+\/]{43}={0,1}$/', $hash)) {
            return null;
        }

        $raw = base64_decode(strtr(rtrim($hash, '='), '-_', '+/').'=', true);

        return ($raw !== false && strlen($raw) === 32) ? bin2hex($raw) : null;
    }

    // -----------------------------------------------------------------
    // Amounts & time
    // -----------------------------------------------------------------

    /**
     * The coin amount for a toman price at a given rate, in the network's
     * smallest unit. The fork rounded the coin amount UP to four decimals so
     * the shop can never be underpaid; this is that rule.
     */
    public static function toUnits(int $toman, float $rate, string $network): int
    {
        if ($rate <= 0) {
            return 0;
        }

        $base = (int) self::nets()[$network]['base'];
        $coin = ceil($toman / $rate * 10000 - 1e-9) / 10000;
        $units = $coin * $base;

        return ($units > 0 && $units < 9.0e15) ? (int) round($units) : 0;
    }

    /** "150.00" style display, the fork's `fmtUnits()`. */
    public static function fmtUnits(int $units, string $network): string
    {
        $base = (int) self::nets()[$network]['base'];
        $decimals = (int) self::nets()[$network]['decimals'];
        $whole = intdiv($units, $base);
        $fraction = str_pad((string) ($units % $base), $decimals, '0', STR_PAD_LEFT);
        $value = $whole.'.'.$fraction;
        $value = rtrim(rtrim($value, '0'), '.');

        if (! str_contains($value, '.')) {
            return $value.'.00';
        }

        return strlen(explode('.', $value)[1]) < 2 ? $value.'0' : $value;
    }

    public static function explorerUrl(string $network, string $hash): string
    {
        return self::nets()[$network]['explorer'].$hash;
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_WAITING => 'در انتظار تایید بلاکچین',
            self::STATUS_COMPLETED, 'CONFIRMED' => 'تایید شده',
            self::STATUS_REVIEW => 'در انتظار بررسی ادمین',
            self::STATUS_REJECTED => 'رد شده',
            self::STATUS_EXPIRED => 'منقضی',
            self::STATUS_CANCELED => 'لغو شده',
            default => $status,
        };
    }

    public function statusText(CryptoPayment $order): string
    {
        return match ($order->status) {
            self::STATUS_COMPLETED, 'CONFIRMED' => $this->msg('status_confirmed'),
            self::STATUS_REVIEW => $this->msg('status_review'),
            self::STATUS_REJECTED => $this->msg('status_rejected'),
            self::STATUS_EXPIRED => $this->msg('status_expired'),
            self::STATUS_CANCELED => $this->msg('status_canceled'),
            default => 'وضعیت سفارش: '.$this->statusLabel($order->status),
        };
    }

    /**
     * Datetimes are stored as Asia/Tehran wall-clock strings; this turns one
     * back into a unix timestamp for the chain comparisons.
     */
    private function epoch(CryptoPayment $order, string $field): ?int
    {
        $raw = $order->getRawOriginal($field) ?? $order->getAttributes()[$field] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return (int) $raw->format('U');
        }

        return Carbon::parse((string) $raw, 'Asia/Tehran')->timestamp;
    }

    private function now(int $offsetSeconds = 0): string
    {
        return Carbon::now('Asia/Tehran')->addSeconds($offsetSeconds)->format('Y-m-d H:i:s');
    }

    private function tehran(int $timestamp, string $format = 'H:i:s'): string
    {
        return Carbon::createFromTimestamp($timestamp, 'Asia/Tehran')->format($format);
    }

    private function minToman(): int
    {
        return max(1000, (int) $this->config->get('crypto_min_toman'));
    }

    private function expiryMinutes(): int
    {
        $minutes = (int) $this->config->get('crypto_expiry_minutes');

        return max(5, min(1440, $minutes > 0 ? $minutes : 60));
    }

    // -----------------------------------------------------------------
    // Small helpers
    // -----------------------------------------------------------------

    private function ownedOrder(int $orderId, string $chatId): ?CryptoPayment
    {
        $order = CryptoPayment::query()->find($orderId);

        if ($order === null || (string) $order->chat_id !== (string) $chatId) {
            return null;
        }

        return $order;
    }

    /**
     * @param  array<int, array<int, array<string, string>>>  $kb
     * @return array{ok: false, text: string, order: null, kb: array<int, array<int, array<string, string>>>}
     */
    private function fail(string $text, array $kb = []): array
    {
        return ['ok' => false, 'text' => $text, 'order' => null, 'kb' => $kb];
    }

    /**
     * @param  array<int, array<int, array<string, string>>>|null  $kb
     * @return array{status: string, text: string, kb: array<int, array<int, array<string, string>>>|null}
     */
    private function res(string $status, string $text, ?array $kb = null): array
    {
        return ['status' => $status, 'text' => $text, 'kb' => $kb];
    }
}
