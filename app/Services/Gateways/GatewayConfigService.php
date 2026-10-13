<?php

declare(strict_types=1);

namespace App\Services\Gateways;

use App\Models\PaymentGateway;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The online payment gateway configuration the admin panel edits.
 *
 * Port of the configuration side of the fork's gateways/index.php and
 * OnlineGatewayService (the bot's request/verify flow is a later wave): every
 * supported driver keeps one row in `payment_gateways` and the panel is one
 * form per row. The rows are seeded lazily from the driver catalogue, exactly
 * like the fork's OnlineGatewayService::ensureRows() did, so a fresh install
 * shows all three gateways inactive until an admin fills them in.
 *
 * Secrets (merchant_id, api_key, secret_key) live in the row and must never
 * be logged by anything in this service.
 */
class GatewayConfigService
{
    /** The drivers this panel can configure, in display order. */
    public const DRIVERS = [
        'zarinpal' => 'زرین‌پال',
        'nextpay' => 'نکست‌پی',
        'nowpayments' => 'NOWPayments',
    ];

    /** The row title seeded for each driver. */
    public const TITLES = [
        'zarinpal' => 'درگاه زرین‌پال',
        'nextpay' => 'درگاه نکست‌پی',
        'nowpayments' => 'درگاه NOWPayments (کریپتو)',
    ];

    /**
     * What must be filled in before a driver may be activated. NOWPayments
     * additionally needs `config_json.usd_rate_toman`, because its orders are
     * priced in USD and the conversion runs off that rate.
     *
     * @var array<string, list<string>>
     */
    public const REQUIRED_FIELDS = [
        'zarinpal' => ['merchant_id'],
        'nextpay' => ['api_key'],
        'nowpayments' => ['api_key', 'config_json.usd_rate_toman'],
    ];

    /** The sort order seeded per driver (the fork's meta sort). */
    private const SORT_ORDER = [
        'zarinpal' => 10,
        'nextpay' => 20,
        'nowpayments' => 30,
    ];

    /** The amount unit the fork seeded per driver, as the schema's currency. */
    private const CURRENCIES = [
        'zarinpal' => 'IRR',
        'nextpay' => 'IRT',
        'nowpayments' => 'IRT',
    ];

    /**
     * The supported drivers with their Persian labels.
     *
     * @return array<string, string>
     */
    public function supportedDrivers(): array
    {
        return self::DRIVERS;
    }

    /**
     * Every gateway row — active and inactive — seeding the driver rows that
     * are still missing (inactive until an admin enters a key).
     *
     * @return Collection<int, PaymentGateway>
     */
    public function list(): Collection
    {
        $this->ensureRows();

        return PaymentGateway::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Upsert one gateway, keyed by `gateway_name`.
     *
     * Only the fields present in $fields are written, so the per-driver form
     * rows can each save their own subset. Activating a gateway checks the
     * driver's required fields first and refuses with a validation error when
     * they are missing — the fork stored the row but silently kept it
     * inactive; a visible error is the same contract without the surprise.
     *
     * @param  array<string, mixed>  $fields
     */
    public function save(string $gatewayName, array $fields): PaymentGateway
    {
        $gatewayName = trim($gatewayName);

        if (! isset(self::DRIVERS[$gatewayName])) {
            throw ValidationException::withMessages([
                'gateway_name' => 'درگاه پرداخت شناخته‌شده نیست.',
            ]);
        }

        $gateway = $this->seedRow($gatewayName);

        $updates = [];

        foreach (['title', 'api_key', 'merchant_id', 'secret_key', 'currency'] as $key) {
            if (array_key_exists($key, $fields)) {
                $value = $fields[$key];

                $updates[$key] = $value === null || (string) $value === '' ? null : trim((string) $value);
            }
        }

        foreach (['sandbox', 'is_active'] as $key) {
            if (array_key_exists($key, $fields)) {
                $updates[$key] = (bool) $fields[$key];
            }
        }

        if (array_key_exists('min_amount', $fields)) {
            $updates['min_amount'] = max(0.0, (float) str_replace(',', '', (string) $fields['min_amount']));
        }

        if (array_key_exists('sort_order', $fields)) {
            $updates['sort_order'] = (int) $fields['sort_order'];
        }

        if (array_key_exists('config_json', $fields)) {
            $updates['config_json'] = $this->encodeConfig($fields['config_json']);
        }

        if (array_key_exists('usd_rate_toman', $fields)) {
            // Merge into the existing blob instead of replacing it, so a
            // future gateway-specific key survives this save.
            $config = $this->decodeConfig($updates['config_json'] ?? $gateway->config_json);
            $config['usd_rate_toman'] = max(0, (int) $fields['usd_rate_toman']);
            $updates['config_json'] = json_encode($config, JSON_UNESCAPED_UNICODE);
        }

        if ((bool) ($updates['is_active'] ?? $gateway->is_active)) {
            $this->assertActivatable($gatewayName, array_merge($gateway->getAttributes(), $updates));
        }

        $gateway->forceFill($updates)->save();

        return $gateway;
    }

    /**
     * Flip one gateway's active flag inline.
     *
     * Activation runs the same required-field check as a full save, so the
     * quick toggle can never switch on a gateway an admin never configured.
     */
    public function toggle(string $gatewayName, bool $active): PaymentGateway
    {
        return $this->save($gatewayName, ['is_active' => $active]);
    }

    /**
     * The gateway-specific settings blob as an array.
     *
     * @return array<string, mixed>
     */
    public function config(PaymentGateway $gateway): array
    {
        return $this->decodeConfig($gateway->config_json);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Insert the driver rows that are still missing. Idempotent.
     */
    private function ensureRows(): void
    {
        foreach (self::DRIVERS as $name => $label) {
            $this->seedRow($name);
        }
    }

    private function seedRow(string $gatewayName): PaymentGateway
    {
        return PaymentGateway::query()->firstOrCreate(
            ['gateway_name' => $gatewayName],
            [
                'title' => self::TITLES[$gatewayName],
                'is_active' => false,
                'api_key' => null,
                'merchant_id' => null,
                'secret_key' => null,
                'sandbox' => $gatewayName !== 'nextpay',
                'currency' => self::CURRENCIES[$gatewayName],
                'min_amount' => 10000,
                'sort_order' => self::SORT_ORDER[$gatewayName],
                'config_json' => null,
            ],
        );
    }

    /**
     * The fork's rule, per driver: no activation without the key the driver
     * charges with, and for NOWPayments also the USD rate.
     *
     * @param  array<string, mixed>  $values
     */
    private function assertActivatable(string $gatewayName, array $values): void
    {
        if ($gatewayName === 'zarinpal' && trim((string) ($values['merchant_id'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'merchant_id' => 'برای فعال کردن زرین‌پال، مرچنت‌کد (Merchant ID) را وارد کنید.',
            ]);
        }

        if (in_array($gatewayName, ['nextpay', 'nowpayments'], true)
            && trim((string) ($values['api_key'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'api_key' => 'بدون کلید API نمی‌شود درگاه را فعال کرد.',
            ]);
        }

        if ($gatewayName === 'nowpayments' && $this->usdRate($values['config_json'] ?? null) <= 0.0) {
            throw ValidationException::withMessages([
                'usd_rate_toman' => 'برای NOWPayments باید «نرخ دلار (تومان)» را وارد کنید.',
            ]);
        }
    }

    private function usdRate(mixed $config): float
    {
        $rate = $this->decodeConfig($config)['usd_rate_toman'] ?? 0;

        return (float) $rate;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(mixed $config): array
    {
        if (is_array($config)) {
            return $config;
        }

        $decoded = json_decode((string) $config, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encodeConfig(mixed $config): ?string
    {
        if ($config === null) {
            return null;
        }

        if (is_array($config)) {
            return json_encode($config, JSON_UNESCAPED_UNICODE);
        }

        $json = trim((string) $config);

        if ($json === '') {
            return null;
        }

        if (! is_array(json_decode($json, true))) {
            throw ValidationException::withMessages([
                'config_json' => 'تنظیمات جانبی درگاه باید یک JSON معتبر باشد.',
            ]);
        }

        return $json;
    }
}
