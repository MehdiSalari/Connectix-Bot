<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Bot\EmojiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * مدیریت ایموجی‌ها — the central emoji registry and imported premium packs.
 *
 * Port of the fork's emojis/index.php: section tabs, a per-emoji edit row
 * (fallback char + premium Custom Emoji ID), the import-pack form and the
 * star favourites on the imported packs.
 */
class AdminBotEmojiController extends Controller
{
    public function __construct(
        private readonly EmojiService $emojis,
    ) {}

    public function index(Request $request): View
    {
        $section = (string) $request->query('section', '');

        return view('admin.emojis.index', [
            'active' => 'emojis',
            'title' => 'ایموجی‌های پرمیوم',
            'sections' => EmojiService::SECTIONS,
            'section' => $section,
            'grouped' => $this->emojis->sections(),
            'packs' => $this->emojis->packList(),
            'packEmojis' => collect($this->emojis->packList())
                ->mapWithKeys(fn (array $pack): array => [
                    $pack['set_name'] => $this->emojis->packEmojis($pack['set_name']),
                ])
                ->all(),
        ]);
    }

    /**
     * One emoji's save (create or update by key) — the per-card form and the
     * «تعریف کلید جدید» form both land here.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'emoji_key' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:190'],
            'section' => ['required', 'string', 'max:64'],
            'emoji_char' => ['required', 'string', 'max:32'],
            'custom_emoji_id' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $this->emojis->upsert(
            $validated['emoji_key'],
            $validated['name'],
            $validated['section'],
            $validated['emoji_char'],
            (string) ($validated['custom_emoji_id'] ?? ''),
            (string) ($validated['description'] ?? ''),
        );

        return back()
            ->with('success', 'ایموجی با موفقیت ذخیره شد.')
            ->withFragment('emoji-card-'.$validated['emoji_key']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'emoji_key' => ['required', 'string', 'max:64'],
        ]);

        $deleted = $this->emojis->delete($validated['emoji_key']);

        return back()->with($deleted ? 'success' : 'error', $deleted ? 'ایموجی با موفقیت حذف شد.' : 'خطا در حذف ایموجی.');
    }

    /**
     * The import-pack form: one row of a premium sticker set, keyed by its
     * custom_emoji_id. Re-importing the same id refreshes the row.
     */
    public function importPack(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'set_name' => ['required', 'string', 'max:190'],
            'custom_emoji_id' => ['required', 'string', 'max:100'],
            'emoji_char' => ['required', 'string', 'max:32'],
        ]);

        $this->emojis->importPack(
            $validated['set_name'],
            $validated['custom_emoji_id'],
            $validated['emoji_char'],
        );

        return back()->with('success', 'پک ایموجی وارد شد. شناسه‌ها را می‌توانید در فرم هر کلید جای‌گذاری کنید.');
    }

    /**
     * The star toggle on one imported emoji.
     */
    public function toggleFavorite(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'custom_emoji_id' => ['required', 'string', 'max:100'],
        ]);

        $state = $this->emojis->toggleFavorite($validated['custom_emoji_id']);

        if ($state === null) {
            return back()->with('error', 'این ایموجی در پک‌های وارد‌شده پیدا نشد.');
        }

        return back()->with('success', $state ? 'به علاقه‌مندی‌ها اضافه شد.' : 'از علاقه‌مندی‌ها حذف شد.');
    }

    /**
     * Remove a whole imported pack from the picker.
     */
    public function destroyPack(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'set_name' => ['required', 'string', 'max:190'],
        ]);

        $deleted = $this->emojis->deletePack($validated['set_name']);

        return back()->with($deleted ? 'success' : 'error', $deleted ? 'پک از لیست انتخاب حذف شد.' : 'پک پیدا نشد.');
    }
}
