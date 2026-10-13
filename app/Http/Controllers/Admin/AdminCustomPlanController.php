<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Panel\PanelSettingsService;
use App\Services\Shop\CustomPlanConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * پلن سفارشی — the custom volume/duration plan pricing and limits.
 *
 * Port of the fork's custom-plan/index.php settings form. The bot texts are
 * stored in panel_settings (customplan_text_*) rather than columns, so the
 * form here posts the same field names the fork did and the service splits
 * them across the two stores.
 */
class AdminCustomPlanController extends Controller
{
    public function __construct(
        private readonly CustomPlanConfigService $plans,
    ) {}

    public function index(): View
    {
        $settings = $this->plans->all();

        return view('admin.custom-plan.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'settings' => $settings,
            // The fork's live pricing example, computed from the stored rates.
            'exampleGb' => 20,
            'exampleDays' => 30,
            'examplePrice' => $this->plans->priceFor(20, 30),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'price_per_gb' => ['required', 'numeric', 'min:0'],
            'price_per_day' => ['required', 'numeric', 'min:0'],
            'min_gb' => ['required', 'numeric', 'min:0.1'],
            'max_gb' => ['required', 'numeric', 'min:0.1'],
            'min_days' => ['required', 'integer', 'min:1'],
            'max_days' => ['required', 'integer', 'min:1'],
            'default_devices' => ['required', 'integer', 'min:1'],
            'text_ask_gb' => ['nullable', 'string', 'max:1000'],
            'text_ask_days' => ['nullable', 'string', 'max:1000'],
            'text_summary_title' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->plans->save([
                'is_active' => $request->boolean('is_active'),
                'price_per_gb' => $validated['price_per_gb'],
                'price_per_day' => $validated['price_per_day'],
                'min_gb' => $validated['min_gb'],
                'max_gb' => $validated['max_gb'],
                'min_days' => $validated['min_days'],
                'max_days' => $validated['max_days'],
                'default_devices' => $validated['default_devices'],
                'text_ask_gb' => $validated['text_ask_gb'] ?? '',
                'text_ask_days' => $validated['text_ask_days'] ?? '',
                'text_summary_title' => $validated['text_summary_title'] ?? '',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('admin.custom-plan.index')
            ->with('success', 'تنظیمات پلن سفارشی ذخیره شد.');
    }
}
