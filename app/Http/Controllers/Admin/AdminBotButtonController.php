<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Bot\BotButtonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * مدیریت دکمه‌های صفحه اصلی — the bot's keyboard buttons.
 *
 * Port of the fork's buttons/index.php: three screen tabs (main menu board
 * with row/column layout, buy screen, OS picker), a per-row inline edit
 * form, the drag-order save, and the default-colour card.
 */
class AdminBotButtonController extends Controller
{
    public function __construct(
        private readonly BotButtonService $buttons,
    ) {}

    public function index(Request $request): View
    {
        $screen = (string) $request->query('screen', 'main_menu');

        if (! isset(BotButtonService::SCREENS[$screen])) {
            $screen = 'main_menu';
        }

        return view('admin.buttons.index', [
            'active' => 'buttons',
            'title' => 'دکمه‌های صفحه اصلی',
            'screens' => BotButtonService::SCREENS,
            'screen' => $screen,
            'buttons' => $this->buttons->getAllButtons($screen),
            'defaultColor' => $this->buttons->defaultColor(),
            'savedKey' => (string) $request->query('saved', ''),
        ]);
    }

    /**
     * One button's inline edit form (label, emoji, premium id, colour,
     * active flag, link/hide-after-test where the action type uses them).
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'button_key' => ['required', 'string', 'max:64'],
            'current_label' => ['required', 'string', 'max:190'],
            'emoji' => ['nullable', 'string', 'max:100'],
            'custom_emoji_id' => ['nullable', 'string', 'max:100'],
            'button_color' => ['nullable', 'string', 'max:20'],
            'link_url' => ['nullable', 'string', 'max:2000'],
            'hide_after_test' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->buttons->upsert($validated['button_key'], $validated);

        return redirect()
            ->route('admin.buttons.index', ['screen' => $request->query('screen', 'main_menu'), 'saved' => $validated['button_key']])
            ->with('success', 'دکمه «'.$validated['button_key'].'» با موفقیت ذخیره شد.');
    }

    /**
     * The drag-and-drop board's save: the full layout in one request.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.id' => ['required', 'integer'],
            'rows.*.sort_order' => ['required', 'integer', 'min:0'],
            'rows.*.row_num' => ['required', 'integer', 'min:1'],
            'rows.*.col_num' => ['required', 'integer', 'min:1'],
        ]);

        $this->buttons->reorder($validated['rows']);

        return back()->with('success', 'چیدمان ذخیره شد.');
    }

    /**
     * The «رنگ پیش‌فرض دکمه‌های بدون رنگ» card.
     */
    public function defaultColor(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_button_color' => ['required', 'string'],
        ]);

        $this->buttons->saveDefaultColor($validated['default_button_color']);

        return back()->with('success', 'رنگ پیش‌فرض دکمه‌های بدون رنگ ذخیره شد.');
    }

    /**
     * One button's show/hide switch (the badge click).
     */
    public function toggle(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'button_key' => ['required', 'string', 'max:64'],
        ]);

        $button = $this->buttons->toggle($validated['button_key']);

        return back()->with('success', $button->is_active ? 'دکمه فعال شد.' : 'دکمه غیرفعال شد.');
    }
}
