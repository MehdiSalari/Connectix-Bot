<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Gateways\GatewayConfigService;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * درگاه‌های پرداخت — the online payment gateway configuration.
 *
 * Port of the fork's gateways/index.php admin form: one card per gateway row
 * with its key, sandbox flag, minimum amount and the active toggle, plus the
 * USD rate only for the driver that prices orders in dollars. The bot-side
 * request/verify flow is a later wave; this page only writes the rows it will
 * read.
 */
class AdminGatewayController extends Controller
{
    public function __construct(
        private readonly GatewayConfigService $gateways,
    ) {}

    public function index(): View
    {
        $rows = $this->gateways->list();

        return view('admin.gateways.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'drivers' => $this->gateways->supportedDrivers(),
            'gateways' => $rows,
            'keyLabels' => [
                'zarinpal' => 'مرچنت‌کد (Merchant ID)',
                'nextpay' => 'کلید API (api_key)',
                'nowpayments' => 'کلید API (API Key)',
            ],
            'hints' => [
                'zarinpal' => 'مرچنت‌کد ۳۶ کاراکتری از پنل زرین‌پال.',
                'nextpay' => 'کلید API از پنل نکست‌پی (nextpay.org).',
                'nowpayments' => 'API Key از پنل NOWPayments. مبلغ به دلار حساب می‌شود، پس «نرخ دلار (تومان)» الزامی است.',
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gateway_name' => ['required', Rule::in(array_keys(GatewayConfigService::DRIVERS))],
            'title' => ['nullable', 'string', 'max:190'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'merchant_id' => ['nullable', 'string', 'max:190'],
            'secret_key' => ['nullable', 'string', 'max:500'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'usd_rate_toman' => ['nullable', 'integer', 'min:0'],
            'config_json' => ['nullable', 'string'],
            'sandbox' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $fields = [
            'title' => $validated['title'] ?? null,
            'api_key' => $validated['api_key'] ?? null,
            'merchant_id' => $validated['merchant_id'] ?? null,
            'secret_key' => $validated['secret_key'] ?? null,
            'sandbox' => $request->boolean('sandbox'),
            'is_active' => $request->boolean('is_active'),
            'min_amount' => (float) ($validated['min_amount'] ?? 0),
        ];

        if ($validated['gateway_name'] === 'nowpayments') {
            $fields['usd_rate_toman'] = (int) ($validated['usd_rate_toman'] ?? 0);
        }

        try {
            $this->gateways->save($validated['gateway_name'], $fields);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('admin.gateways.index')
            ->with('success', 'تنظیمات درگاه با موفقیت ذخیره شد.');
    }

    public function toggle(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gateway_name' => ['required', Rule::in(array_keys(GatewayConfigService::DRIVERS))],
            'active' => ['required', 'boolean'],
        ]);

        try {
            $this->gateways->toggle($validated['gateway_name'], (bool) $validated['active']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()
            ->route('admin.gateways.index')
            ->with('success', 'وضعیت درگاه تغییر کرد.');
    }
}
