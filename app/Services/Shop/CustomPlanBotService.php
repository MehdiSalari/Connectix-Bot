<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\CustomPlanSetting;
use App\Services\Crypto\CryptoPayService;
use App\Services\Gateways\OnlineGatewayService;
use Illuminate\Support\Facades\Schema;

class CustomPlanBotService
{
    public static function settings(): array
    {
        if (! class_exists('App\\Models\\CustomPlanSetting')) {
            return self::defaults();
        }

        if (! Schema::hasTable('custom_plan_settings')) {
            return self::defaults();
        }

        $settings = CustomPlanSetting::query()->find(1);

        if ($settings === null) {
            return self::defaults();
        }

        $row = $settings->toArray();

        return [
            'is_enabled' => (bool) ($row['is_active'] ?? ($settings->is_active ?? true)),
            'is_active' => (bool) ($row['is_active'] ?? ($settings->is_active ?? true)),
            'price_per_gb' => (int) $row['price_per_gb'],
            'price_per_day' => (int) $row['price_per_day'],
            'min_gb' => (float) $row['min_gb'],
            'max_gb' => (float) $row['max_gb'],
            'min_days' => (int) $row['min_days'],
            'max_days' => (int) $row['max_days'],
            'default_devices' => (int) ($row['default_devices'] ?? 1),
            'days_options' => $row['days_options'] ?? '[7,14,30,60,90,180,270,360]',
        ];
    }

    private static function defaults(): array
    {
        return [
            'is_enabled' => true,
            'is_active' => true,
            'price_per_gb' => 5000,
            'price_per_day' => 1000,
            'min_gb' => 1.0,
            'max_gb' => 1000.0,
            'min_days' => 1,
            'max_days' => 365,
            'default_devices' => 1,
            'days_options' => '[7,14,30,60,90,180,270,360]',
        ];
    }

    public static function priceFor(int $gb, int $days): array
    {
        $s = self::settings();

        if (empty($s['is_enabled']) || empty($s['is_active'])) {
            return [
                'ok' => false,
                'error' => 'این قابلیت در حال حاضر غیرفعال است.',
            ];
        }

        $minGb = (int) $s['min_gb'];
        $maxGb = (int) $s['max_gb'];
        $minDays = (int) $s['min_days'];
        $maxDays = (int) $s['max_days'];

        if ($gb < $minGb) {
            return [
                'ok' => false,
                'error' => 'حداقل حجم قابل انتخاب '.self::fmt($s['min_gb']).' گیگابایت است.',
            ];
        }

        if ($gb > $maxGb) {
            return [
                'ok' => false,
                'error' => 'حداکثر حجم قابل انتخاب '.self::fmt($s['max_gb']).' گیگابایت است.',
            ];
        }

        if ($days < $minDays) {
            return [
                'ok' => false,
                'error' => 'این مدت دیگر در دسترس نیست، لطفاً از ابتدا شروع کنید.',
            ];
        }

        if ($days > $maxDays) {
            return [
                'ok' => false,
                'error' => 'این مدت دیگر در دسترس نیست، لطفاً از ابتدا شروع کنید.',
            ];
        }

        $total = ($gb * (int) $s['price_per_gb']) + ($days * (int) $s['price_per_day']);

        return [
            'ok' => true,
            'total' => $total,
            'gb' => $gb,
            'days' => $days,
            'breakdown' => [
                'gb_price' => (int) $s['price_per_gb'],
                'day_price' => (int) $s['price_per_day'],
            ],
        ];
    }

    private static function fmt($n): string
    {
        $f = (float) $n;
        if (floor($f) == $f) {
            return (string) (int) $f;
        }

        return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
    }

    public static function buildOrderText(int $gb, int $days, int $total): string
    {
        return 'سفارش پلن سفارشی: '.$gb.' گیگابایت / '.$days.' روز - جمع: '.number_format($total).' تومان';
    }

    public static function paymentMethodsRows(string $mode = 'buy'): array
    {
        $rows = [
            [
                [
                    'text' => '💰 | کیف پول',
                    'callback_data' => 'pay_wallet:custom:'.$mode,
                ],
            ],
        ];

        try {
            if (class_exists('App\\Services\\Gateways\\OnlineGatewayService')) {
                $gwRows = OnlineGatewayService::buttonRows($mode);
                foreach ($gwRows as $r) {
                    $rows[] = $r;
                }
            }
        } catch (\Throwable $e) {
            // ignore if not loadable in test context
        }

        try {
            if (class_exists('App\\Services\\Crypto\\CryptoPayService')) {
                $netRows = CryptoPayService::nets();
                foreach ($netRows as $r) {
                    $rows[] = $r;
                }
            }
        } catch (\Throwable $e) {
            // ignore if not loadable in test context
        }

        return $rows;
    }
}
