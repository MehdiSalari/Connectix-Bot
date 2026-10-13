<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Crypto\CryptoConfigService;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * پرداخت کریپتو — the TRX/TON payment settings.
 *
 * Port of the settings card of the fork's crypto/index.php (its payments
 * table and bot-texts cards belong to the waves that port the flow). The live
 * rate preview of the fork («تست نرخ خودکار») is deliberately not ported: it
 * calls Nobitex/TronGrid/toncenter from the panel, and this panel makes no
 * outbound calls from admin forms.
 */
class AdminCryptoController extends Controller
{
    public function __construct(
        private readonly CryptoConfigService $crypto,
    ) {}

    public function index(): View
    {
        $settings = $this->crypto->all();

        return view('admin.crypto.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'settings' => $settings,
            'networks' => CryptoConfigService::NETWORKS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'crypto_enabled' => ['nullable', 'boolean'],
            'crypto_auto_confirm' => ['nullable', 'boolean'],
            'crypto_expiry_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'crypto_min_toman' => ['nullable', 'integer', 'min:0'],
            'crypto_nobitex_base' => ['nullable', 'string', 'max:255'],
            'crypto_custom_usdt_url' => ['nullable', 'string', 'max:500'],
            'crypto_custom_usdt_text' => ['nullable', 'string', 'max:500'],
            'networks' => ['nullable', 'array'],
            'networks.trx.enabled' => ['nullable', 'boolean'],
            'networks.trx.address' => ['nullable', 'string', 'max:120'],
            'networks.trx.rate_mode' => ['nullable', Rule::in(['manual', 'auto'])],
            'networks.trx.rate_toman' => ['nullable', 'numeric'],
            'networks.trx.api_key' => ['nullable', 'string', 'max:255'],
            'networks.trx.api_base' => ['nullable', 'string', 'max:255'],
            'networks.ton.enabled' => ['nullable', 'boolean'],
            'networks.ton.address' => ['nullable', 'string', 'max:120'],
            'networks.ton.rate_mode' => ['nullable', Rule::in(['manual', 'auto'])],
            'networks.ton.rate_toman' => ['nullable', 'numeric'],
            'networks.ton.api_key' => ['nullable', 'string', 'max:255'],
            'networks.ton.api_base' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'crypto_enabled' => $request->boolean('crypto_enabled'),
            'crypto_auto_confirm' => $request->boolean('crypto_auto_confirm'),
            'crypto_expiry_minutes' => (int) ($validated['crypto_expiry_minutes'] ?? 30),
            'crypto_min_toman' => (int) ($validated['crypto_min_toman'] ?? 10000),
            'crypto_nobitex_base' => $validated['crypto_nobitex_base'] ?? '',
            'crypto_custom_usdt_url' => $validated['crypto_custom_usdt_url'] ?? '',
            'crypto_custom_usdt_text' => $validated['crypto_custom_usdt_text'] ?? '',
            CryptoConfigService::NETWORKS_KEY => [
                'trx' => $validated['networks']['trx'] ?? [],
                'ton' => $validated['networks']['ton'] ?? [],
            ],
        ];

        try {
            $this->crypto->save($payload);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('admin.crypto.index')
            ->with('success', 'تنظیمات پرداخت کریپتو ذخیره شد.');
    }
}
