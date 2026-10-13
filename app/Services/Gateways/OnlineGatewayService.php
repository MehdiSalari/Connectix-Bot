<?php

declare(strict_types=1);

namespace App\Services\Gateways;

use App\Enums\PaymentMethod;
use App\Enums\WalletTransactionStatus;
use App\Models\GatewayOrder;
use App\Models\PaymentGateway;
use App\Services\Panel\PanelSettingsService;
use App\Services\Payment\PaymentService;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
                if (!$meta) {
                    continue;
                }
                if ($driver['gateway_name'] === 'nowpayments') {
                    $cfg = json_decode((string)($driver['config_json'] ?? ''), true);
                    $rate = (float)($cfg['usd_rate_toman'] ?? 0);
                    if ($rate <= 0) {
                        continue;
                    }
                }
                $rows[] = [[
                    'text' => $meta['emoji'].' | '.$meta['title'],
                    'callback_data' => 'gw_go:'.$driver['gateway_name'].':'.($mode === 'wallet' ? 'wallet' : 'buy'),
                ]];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $rows;
    }
}