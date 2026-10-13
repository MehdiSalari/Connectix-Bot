<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ظاهر و تم — the panel's default skin choices.
 *
 * Port of the fork's appearance/index.php, minus the three-style panel switch
 * (this panel is the Airoxnet dashboard, so only the theme id is selectable).
 * The saved pair lands in the same key/value store the settings page uses:
 * `panel_theme` is what a browser that has not picked its own theme shows and
 * `webapp_theme` is what the Telegram WebApp opens with; both may be null,
 * which is the fork's «auto» — dark by default in the panel, Telegram's own
 * scheme inside the WebApp.
 */
class AdminAppearanceController extends Controller
{
    /** The ten theme ids, in display order. */
    public const THEMES = [
        'dark' => 'تیره بنفش',
        'light' => 'روشن بنفش',
        'airox-gold' => 'تیره طلایی',
        'airox-violet' => 'روشن بنفش (Airoxnet)',
        'ocean' => 'اقیانوس',
        'forest' => 'جنگل',
        'sunset' => 'غروب',
        'rose' => 'رز',
        'midnight' => 'نیمه‌شب',
        'graphite' => 'گرافیت',
    ];

    public function __construct(
        private readonly PanelSettingsService $settings,
    ) {}

    public function show(): View
    {
        $overrides = $this->settings->overrides();

        return view('admin.appearance.index', [
            'active' => 'appearance',
            'title' => 'ظاهر و تم',
            'themes' => self::THEMES,
            'panelTheme' => $this->validTheme($overrides['panel_theme'] ?? null),
            'webappTheme' => $this->validTheme($overrides['webapp_theme'] ?? null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'panel_theme' => ['nullable', Rule::in(array_keys(self::THEMES))],
            'webapp_theme' => ['nullable', Rule::in(array_keys(self::THEMES))],
        ]);

        $this->settings->saveOverrides([
            'panel_theme' => $validated['panel_theme'] ?? '',
            'webapp_theme' => $validated['webapp_theme'] ?? '',
        ]);

        return redirect()
            ->route('admin.appearance')
            ->with('success', 'تنظیمات ظاهر ذخیره شد.');
    }

    /** An empty or unknown stored id reads as «no explicit default». */
    private function validTheme(?string $id): ?string
    {
        return $id !== null && $id !== '' && isset(self::THEMES[$id]) ? $id : null;
    }
}
