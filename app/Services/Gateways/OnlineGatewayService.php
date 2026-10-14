<?php

declare(strict_types=1);

namespace App\Services\Gateways;

use App\Models\GatewayOrder;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Services\Panel\PanelSettingsService;
use App\Services\Payment\PaymentService;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OnlineGatewayService
{
    public const EXPIRY_MINUTES = 45;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly PaymentService $payments,
        private readonly WalletService $wallets,
        private readonly UserStateService $state,
        private readonly PanelSettingsService $panelSettings,
    ) {}

    public static function meta(): array
    {
        return [
            'zarinpal' => [
                'title' => 'درگاه زرین‌پال',
                'emoji' => '🟡',
                'unit' => 'rial',
                'sort' => 10,
            ],
            'nextpay' => [
                'title' => 'درگاه نکست‌پی',
                'emoji' => '🔵',
                'unit' => 'toman',
                'sort' => 20,
            ],
            'nowpayments' => [
                'title' => 'درگاه NOWPayments (کریپتو)',
                'emoji' => '🪙',
                'unit' => 'toman',
                'sort' => 30,
            ],
        ];
    }

    public static function activeDrivers(): array
    {
        try {
            return PaymentGateway::query()
                ->where('is_active', true)
                ->whereNotNull('api_key')
                ->where('api_key', '!=', '')
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function buttonRows(string $mode): array
    {
        $rows = [];
        try {
            $drivers = self::activeDrivers();
            foreach ($drivers as $driver) {
                $meta = self::meta()[$driver['gateway_name']] ?? null;
                if (! $meta) {
                    continue;
                }
                if ($driver['gateway_name'] === 'nowpayments') {
                    $cfg = json_decode((string) ($driver['config_json'] ?? ''), true);
                    $rate = (float) ($cfg['usd_rate_toman'] ?? 0);
                    if ($rate <= 0) {
                        continue;
                    }
                }
                $rows[] = [
                    [
                        'text' => $meta['emoji'].' | '.$meta['title'],
                        'callback_data' => 'gw_go:'.$driver['gateway_name'].':'.($mode === 'wallet' ? 'wallet' : 'buy'),
                    ],
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $rows;
    }

    public function createOrder(string $chatId, string $driver, string $mode, array $state, int $amountToman = 0): array
    {
        $gateway = PaymentGateway::query()
            ->where('gateway_name', $driver)
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            return ['ok' => false, 'text' => 'این درگاه فعال نیست.', 'kb' => [], 'order' => null];
        }

        if ($amountToman <= 0) {
            $amountToman = (int) ($state['amount'] ?? $state['price'] ?? 0);
        }

        $min = $gateway->min_amount ?? 0;
        if ((int) $min > 0 && $amountToman < (int) $min) {
            return ['ok' => false, 'text' => 'حداقل مبلغ برای این درگاه '.number_format((int) $min).' تومان است.', 'kb' => [], 'order' => null];
        }

        $config = json_decode((string) ($gateway->config_json ?? ''), true) ?: [];
        // Fork parity: the callback controller recovers `t` with a strict
        // hex filter ([a-f0-9]), so the token must be hex or every real
        // return URL would 404.
        $token = bin2hex(random_bytes(16));
        $callback = url('/pay/gateway/callback?t='.$token.'&gw='.$driver);

        try {
            if ($driver === 'zarinpal') {
                $merchantId = $gateway->merchant_id ?: $gateway->api_key;
                $amountSent = $amountToman * 10;
                $res = Http::post('https://id.zarinpal.com/pg/v4/PaymentRequest', [
                    'merchant_id' => $merchantId,
                    'amount' => $amountSent,
                    'description' => 'سفارش پرداخت',
                    'callback_url' => $callback,
                    'metadata' => ['email' => '', 'mobile' => ''],
                ]);
                $data = $res->json();
                $authority = $data['data']['authority'] ?? null;
                if (($data['data']['code'] ?? null) !== 100 || empty($authority)) {
                    return ['ok' => false, 'text' => 'این درگاه فعال نیست.', 'kb' => [], 'order' => null];
                }
                $payUrl = $data['data']['pay_url'] ?? ('https://id.zarinpal.com/pg/StartPay/'.$authority);
                $order = GatewayOrder::query()->create([
                    'token' => $token,
                    'chat_id' => $chatId,
                    'kind' => $mode === 'wallet' ? 'wallet' : 'buy',
                    'gateway' => 'zarinpal',
                    'amount_toman' => $amountToman,
                    'amount_sent' => $amountSent,
                    'status' => 'WAITING',
                    'authority' => $authority,
                    'pay_url' => $payUrl,
                    'created_at' => Carbon::now('Asia/Tehran'),
                    'updated_at' => Carbon::now('Asia/Tehran'),
                ]);

                return ['ok' => true, 'text' => '', 'kb' => [], 'order' => $order];
            } elseif ($driver === 'nextpay') {
                $res = Http::post('https://api.nextpay.ir/np/gateway/token', [
                    'api_key' => $gateway->api_key,
                    'order_id' => Str::random(8),
                    'amount' => $amountToman,
                    'callback_url' => $callback,
                    'redirect_url' => $callback,
                ]);
                $data = $res->json();
                $code = $data['code'] ?? null;
                if ($code === -1 && ! empty($data['token'])) {
                    $authority = $data['token'];
                    $payUrl = 'https://nextpay.ir/np/gateway/'.$authority;
                } elseif ($code === -1 && ! empty($data['trans_id'])) {
                    $authority = $data['trans_id'];
                    $payUrl = 'https://nextpay.ir/np/gateway/payment/'.$authority;
                } else {
                    return ['ok' => false, 'text' => 'این درگاه فعال نیست.', 'kb' => [], 'order' => null];
                }
                $order = GatewayOrder::query()->create([
                    'token' => $token,
                    'chat_id' => $chatId,
                    'kind' => $mode === 'wallet' ? 'wallet' : 'buy',
                    'gateway' => 'nextpay',
                    'amount_toman' => $amountToman,
                    'amount_sent' => $amountToman,
                    'status' => 'WAITING',
                    'authority' => $authority,
                    'pay_url' => $payUrl,
                    'created_at' => Carbon::now('Asia/Tehran'),
                    'updated_at' => Carbon::now('Asia/Tehran'),
                ]);

                return ['ok' => true, 'text' => '', 'kb' => [], 'order' => $order];
            } elseif ($driver === 'nowpayments') {
                $rate = (float) ($config['usd_rate_toman'] ?? 0);
                if ($rate <= 0) {
                    return ['ok' => false, 'text' => 'این درگاه فعال نیست.', 'kb' => [], 'order' => null];
                }
                $usd = round($amountToman / $rate, 2);
                $res = Http::withHeaders(['x-api-key' => $gateway->api_key])->post('https://api.nowpayments.io/v1/invoice', [
                    'price_amount' => $usd,
                    'price_currency' => 'USD',
                    'order_id' => Str::random(8),
                    'callback_url' => $callback,
                    'success_url' => $callback,
                ]);
                $data = $res->json();
                $invoiceUrl = $data['invoice_url'] ?? null;
                if (empty($invoiceUrl)) {
                    return ['ok' => false, 'text' => 'این درگاه فعال نیست.', 'kb' => [], 'order' => null];
                }
                $order = GatewayOrder::query()->create([
                    'token' => $token,
                    'chat_id' => $chatId,
                    'kind' => $mode === 'wallet' ? 'wallet' : 'buy',
                    'gateway' => 'nowpayments',
                    'amount_toman' => $amountToman,
                    'amount_sent' => $usd,
                    'usd_amount' => (string) $usd,
                    'status' => 'WAITING',
                    'authority' => $data['id'] ?? null,
                    'pay_url' => $invoiceUrl,
                    'created_at' => Carbon::now('Asia/Tehran'),
                    'updated_at' => Carbon::now('Asia/Tehran'),
                ]);

                return ['ok' => true, 'text' => '', 'kb' => [], 'order' => $order];
            }

            return ['ok' => false, 'text' => 'این درگاه فعال نیست.', 'kb' => [], 'order' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => 'این درگاه فعال نیست.', 'kb' => [], 'order' => null];
        }
    }

    public function verifyAndFinalize(int $orderId): array
    {
        $order = GatewayOrder::query()->find($orderId);
        if (! $order) {
            return ['status' => 'FAILED', 'message' => 'سفارش پیدا نشد'];
        }

        if ($order->status === 'PAID' || $order->status === 'COMPLETED') {
            return ['status' => 'PAID', 'message' => 'پرداخت قبلاً تایید شده است'];
        }

        if ($order->status !== 'WAITING' && $order->status !== 'PENDING') {
            return ['status' => $order->status, 'message' => $this->statusText($order)];
        }

        $gateway = PaymentGateway::query()->where('gateway_name', $order->gateway)->first();
        if (! $gateway) {
            return ['status' => 'FAILED', 'message' => 'درگاه یافت نشد'];
        }

        $config = json_decode((string) ($gateway->config_json ?? ''), true) ?: [];

        try {
            if ($order->gateway === 'zarinpal') {
                $merchantId = $gateway->merchant_id ?: $gateway->api_key;
                $amountSent = $order->amount_toman * 10;
                $res = Http::post('https://id.zarinpal.com/pg/v4/PaymentVerify', [
                    'merchant_id' => $merchantId,
                    'amount' => $amountSent,
                    'authority' => $order->authority,
                ]);
                $data = $res->json();
                $code = $data['data']['code'] ?? null;
                if ($code === 100 || $code === 101) {
                    return $this->finalizePaidOrder($order);
                }

                return ['status' => 'FAILED', 'message' => 'پرداخت تایید نشد'];
            } elseif ($order->gateway === 'nextpay') {
                $res = Http::post('https://api.nextpay.ir/np/gateway/verify', [
                    'api_key' => $gateway->api_key,
                    'amount' => $order->amount_toman,
                    'trans_id' => $order->authority,
                ]);
                $data = $res->json();
                $code = $data['code'] ?? null;
                if ($code === 0) {
                    return $this->finalizePaidOrder($order);
                }

                return ['status' => 'FAILED', 'message' => 'پرداخت تایید نشد'];
            } elseif ($order->gateway === 'nowpayments') {
                $authority = $order->authority;
                if (empty($authority)) {
                    return ['status' => 'PENDING', 'message' => 'در انتظار تایید'];
                }
                $res = Http::withHeaders(['x-api-key' => $gateway->api_key])->get('https://api.nowpayments.io/v1/invoice/'.$authority);
                $data = $res->json();
                $status = strtolower($data['status'] ?? ($data['payment_status'] ?? ''));
                if (in_array($status, ['paid', 'completed', 'finished'])) {
                    return $this->finalizePaidOrder($order);
                }
                if (in_array($status, ['expired', 'failed', 'refunded'])) {
                    return ['status' => 'FAILED', 'message' => 'پرداخت ناموفق بود'];
                }

                return ['status' => 'PENDING', 'message' => 'در انتظار تایید'];
            }

            return ['status' => 'FAILED', 'message' => 'درگاه پشتیبانی نمی‌شود'];
        } catch (\Throwable $e) {
            return ['status' => 'FAILED', 'message' => 'خطا در تایید پرداخت'];
        }
    }

    private function finalizePaidOrder(GatewayOrder $order)
    {
        DB::transaction(function () use ($order) {
            $order->status = 'COMPLETED';
            $order->paid_at = Carbon::now('Asia/Tehran');
            $order->save();

            if ($order->kind === 'wallet') {
                try {
                    $this->wallets->approveDeposit((int) $order->wallet_tx_id);
                } catch (\Throwable $e) {
                    $order->note = 'wallet_error';
                    $order->save();
                }
            } elseif ($order->kind === 'buy') {
                try {
                    $payment = new Payment([
                        'chat_id' => $order->chat_id,
                        'client_id' => 'new',
                        'plan_id' => 'plan',
                        'price' => number_format($order->amount_toman),
                        'is_paid' => null,
                        'method' => 'gateway',
                        'created_at' => Carbon::now(),
                    ]);
                    $payment->save();
                } catch (\Throwable $e) {
                    $order->note = 'payment_error';
                    $order->status = 'REVIEW';
                    $order->save();
                }
            }
        });

        if ($order->status === 'REVIEW') {
            return ['status' => 'REVIEW', 'message' => 'پرداخت ثبت شد و در حال بررسی است'];
        }

        return ['status' => 'PAID', 'message' => 'پرداخت با موفقیت تایید شد'];
    }

    public function markFailed(int $orderId, string $status): void
    {
        $order = GatewayOrder::query()->find($orderId);
        if ($order) {
            $order->status = $status === 'CANCELED' ? 'CANCELED' : 'FAILED';
            $order->status_text = $status;
            $order->save();
        }
    }

    public function handleNowPaymentsIpn(int $orderId, string $token, array $body): array
    {
        $order = GatewayOrder::query()->find($orderId);
        if (! $order || $order->token !== $token) {
            return ['http' => 404, 'text' => 'not found'];
        }
        $paymentStatus = strtolower($body['payment_status'] ?? ($body['status'] ?? ''));
        if (in_array($paymentStatus, ['paid', 'completed', 'finished'])) {
            $this->verifyAndFinalize($orderId);

            return ['http' => 200, 'text' => 'ok'];
        }
        if (in_array($paymentStatus, ['failed', 'cancelled', 'expired'])) {
            $this->markFailed($orderId, 'FAILED');

            return ['http' => 200, 'text' => 'ok'];
        }

        return ['http' => 200, 'text' => 'ok'];
    }

    public function orderScreen(GatewayOrder $order): array
    {
        return [
            'text' => $this->statusText($order),
            'kb' => [
                [
                    ['text' => '💳 رفتن به پرداخت', 'url' => $order->pay_url],
                ],
                [
                    ['text' => '🔄 بررسی پرداخت', 'callback_data' => 'gw_chk:'.$order->id],
                ],
                [
                    ['text' => '❌ انصراف', 'callback_data' => 'gw_cancel:'.$order->id],
                ],
            ],
        ];
    }

    public function statusText(GatewayOrder $order): string
    {
        return match ($order->status) {
            'PAID', 'COMPLETED' => 'پرداخت موفقیت‌آمیز بود و تکمیل شد',
            'REVIEW' => 'پرداخت ثبت شد و در حال بررسی است',
            'PENDING', 'WAITING' => 'در انتظار پرداخت',
            'CANCELED' => 'پرداخت لغو شد',
            'FAILED', 'EXPIRED' => 'پرداخت ناموفق بود',
            default => 'وضعیت نامشخص',
        };
    }
}
