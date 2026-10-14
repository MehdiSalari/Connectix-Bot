<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The TRX/TON crypto payment settings, stored in `panel_settings`.
 *
 * Port of the configuration side of the fork's crypto/index.php and
 * CryptoPayService::save() (the on-chain verification flow is a later wave).
 * The fork kept everything under one `crypto_pay` JSON key; this panel keeps
 * the top-level switches in their own keys (crypto_*) so the readers the
 * coming waves add can pull a single value cheaply, and the per-network
 * blocks in a `crypto_networks` JSON list.
 *
 * Addresses are validated per network before anything is written: a TRX
 * address starts with T, a TON address with UQ or EQ (the fork also accepted
 * a raw 48-character user-friendly base64 body, accepted here leniently).
 */
class CryptoConfigService
{
    /** The panel_settings key holding the per-network configuration. */
    public const NETWORKS_KEY = 'crypto_networks';

    /** The networks the panel can configure, with their display metadata. */
    public const NETWORKS = [
        'trx' => ['name' => 'ترون (TRX)', 'symbol' => 'TRX', 'prefix' => 'T'],
        'ton' => ['name' => 'تون (TON)', 'symbol' => 'TON', 'prefix' => 'UQ/EQ'],
    ];

    /** Top-level settings: panel_settings key => [default, type]. */
    private const SETTINGS = [
        'crypto_enabled' => [false, 'bool'],
        'crypto_auto_confirm' => [true, 'bool'],
        'crypto_expiry_minutes' => [30, 'int'],
        'crypto_min_toman' => [10000, 'int'],
        'crypto_nobitex_base' => ['https://api.nobitex.ir', 'url'],
        'crypto_custom_usdt_url' => ['', 'url'],
        'crypto_custom_usdt_text' => ['', 'text'],
    ];

    /** Per-network defaults inside the crypto_networks JSON blob. */
    private const NETWORK_DEFAULTS = [
        'address' => '',
        'rate_mode' => 'manual',
        'rate_toman' => 0,
        'api_key' => '',
        'api_base' => '',
    ];

    /**
     * Every setting as an array the panel form can render.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = DB::table('panel_settings')
            ->whereIn('setting_key', array_merge(array_keys(self::SETTINGS), [self::NETWORKS_KEY]))
            ->pluck('setting_value', 'setting_key');

        $settings = [];

        foreach (self::SETTINGS as $key => [$default, $type]) {
            $settings[$key] = $this->cast($stored[$key] ?? null, $default, $type);
        }

        $settings[self::NETWORKS_KEY] = $this->networks($stored[self::NETWORKS_KEY] ?? null);

        return $settings;
    }

    /**
     * One setting, typed. The wave that ports the bot flow reads these.
     */
    public function get(string $key): mixed
    {
        if ($key === self::NETWORKS_KEY) {
            return $this->networks(
                DB::table('panel_settings')->where('setting_key', $key)->value('setting_value')
            );
        }

        if (! isset(self::SETTINGS[$key])) {
            return null;
        }

        [$default, $type] = self::SETTINGS[$key];

        $value = DB::table('panel_settings')->where('setting_key', $key)->value('setting_value');

        return $this->cast($value, $default, $type);
    }

    /**
     * Persist a settings form.
     *
     * Only the keys present in $values are written, so the crypto page can
     * save its own subset. Enabling the feature, or a network, needs a valid
     * wallet address first — the same rule the fork enforced.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function save(array $values): void
    {
        $networks = $this->networks(
            DB::table('panel_settings')->where('setting_key', self::NETWORKS_KEY)->value('setting_value')
        );

        if (array_key_exists(self::NETWORKS_KEY, $values)) {
            foreach (self::NETWORKS as $name => $meta) {
                $block = (array) ($values[self::NETWORKS_KEY][$name] ?? []);

                $networks[$name] = array_merge($networks[$name], [
                    'enabled' => (bool) ($block['enabled'] ?? false),
                    'address' => trim((string) ($block['address'] ?? '')),
                    'rate_mode' => ($block['rate_mode'] ?? '') === 'auto' ? 'auto' : 'manual',
                    'rate_toman' => max(0.0, (float) str_replace(',', '', (string) ($block['rate_toman'] ?? 0))),
                    'api_key' => trim((string) ($block['api_key'] ?? '')),
                    'api_base' => $this->normalizeUrl((string) ($block['api_base'] ?? '')),
                ]);

                if ($networks[$name]['enabled'] && $networks[$name]['address'] === '') {
                    throw ValidationException::withMessages([
                        "{$name}_address" => "برای فعال کردن {$meta['symbol']} آدرس کیف پول را وارد کنید.",
                    ]);
                }

                if ($networks[$name]['address'] !== ''
                    && ! $this->isValidAddress($name, $networks[$name]['address'])) {
                    throw ValidationException::withMessages([
                        "{$name}_address" => "آدرس کیف پول {$meta['symbol']} نامعتبر است (یک‌بار دیگر از کیف پول کپی کنید).",
                    ]);
                }
            }
        }

        $writes = [];

        foreach (self::SETTINGS as $key => [$default, $type]) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $raw = $values[$key];

            if ($type === 'bool') {
                $writes[$key] = (bool) $raw ? '1' : '0';

                continue;
            }

            if ($type === 'int') {
                $writes[$key] = (string) max(0, (int) str_replace(',', '', (string) $raw));

                continue;
            }

            if ($type === 'url') {
                $writes[$key] = $this->normalizeUrl((string) $raw);

                continue;
            }

            $writes[$key] = trim((string) $raw);
        }

        // The feature must not switch on with no configured network behind it.
        if (($writes['crypto_enabled'] ?? null) === '1') {
            $configured = array_filter($networks, fn (array $net): bool => $net['enabled'] && $net['address'] !== '');

            if ($configured === []) {
                throw ValidationException::withMessages([
                    'crypto_enabled' => 'برای فعال کردن پرداخت کریپتو، حداقل یک شبکه (TRX یا TON) را با آدرس کیف پول فعال کنید.',
                ]);
            }
        }

        $this->write($writes);

        if (array_key_exists(self::NETWORKS_KEY, $values)) {
            $this->write([self::NETWORKS_KEY => json_encode($networks, JSON_UNESCAPED_UNICODE)]);
        }
    }

    /**
     * The per-network blocks, merged over the defaults.
     *
     * @return array<string, array<string, mixed>>
     */
    public function networks(?string $json = null): array
    {
        $json ??= (string) DB::table('panel_settings')->where('setting_key', self::NETWORKS_KEY)->value('setting_value');

        $stored = json_decode($json, true);
        $stored = is_array($stored) ? $stored : [];

        $out = [];

        foreach (self::NETWORKS as $name => $meta) {
            $block = array_merge(self::NETWORK_DEFAULTS, is_array($stored[$name] ?? null) ? $stored[$name] : []);

            $block['enabled'] = (bool) ($block['enabled'] ?? false);
            $block['address'] = trim((string) ($block['address'] ?? ''));
            $block['rate_mode'] = ($block['rate_mode'] ?? '') === 'auto' ? 'auto' : 'manual';
            $block['rate_toman'] = (float) ($block['rate_toman'] ?? 0);
            $block['api_key'] = trim((string) ($block['api_key'] ?? ''));
            $block['api_base'] = $this->normalizeUrl((string) ($block['api_base'] ?? ''));

            $out[$name] = $block;
        }

        return $out;
    }

    /**
     * Whether an address is shaped right for the network.
     *
     * TRX: base58check starting with T (34 chars). TON: the user-friendly
     * bounceable/non-bounceable form UQ…/EQ… (48 chars) — the fork also
     * accepted the raw form, matched here leniently as 48 base64url chars.
     */
    public function isValidAddress(string $network, string $address): bool
    {
        return match ($network) {
            'trx' => preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address) === 1,
            'ton' => preg_match('/^(UQ|EQ)[A-Za-z0-9_-]{46}$/', $address) === 1
                || preg_match('/^[A-Za-z0-9_-]{48}$/', $address) === 1,
            default => false,
        };
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param  array<string, string>  $writes
     */
    private function write(array $writes): void
    {
        foreach ($writes as $key => $value) {
            DB::table('panel_settings')->updateOrInsert(
                ['setting_key' => $key],
                ['setting_value' => $value, 'updated_at' => now()],
            );
        }
    }

    private function cast(mixed $value, mixed $default, string $type): mixed
    {
        if ($value === null || trim((string) $value) === '') {
            return $default;
        }

        return match ($type) {
            'bool' => filter_var(trim((string) $value), FILTER_VALIDATE_BOOL),
            'int' => max(0, (int) $value),
            'url' => $this->normalizeUrl((string) $value),
            default => trim((string) $value),
        };
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        return str_contains($url, '?') ? $url : rtrim($url, '/');
    }
}
