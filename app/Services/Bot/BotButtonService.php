<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\BotButton;
use App\Models\PanelSetting;
use Illuminate\Support\Facades\DB;

/**
 * The bot's keyboard buttons, as the admin edits them.
 *
 * Port of the feature fork's `services/ButtonService.php` and its page
 * `buttons/index.php`. One row per button; `screen` groups buttons per
 * keyboard (main_menu, buy_screen, os_screen/…), `current_label` is what the
 * user actually sees, and `button_color`/`custom_emoji_id` carry the Bot API
 * 9.4 native styling.
 *
 * The read half (what the bot renders) mirrors the fork exactly: order by
 * sort_order, then row/col; rows saved before the screen column existed are
 * read as main-menu buttons.
 */
class BotButtonService
{
    /** The screens the admin page tabs over. */
    public const SCREENS = [
        'main_menu' => 'منوی اصلی',
        'buy_screen' => 'صفحه خرید / تمدید',
        'os_screen' => 'صفحه دانلود نرم‌افزار',
    ];

    /**
     * Screen names the fork stored for the OS picker over its lifetime:
     * the original page read `download_software`, the brief for this port
     * calls it `os_screen`. Both are listed as one screen here, so a seeded
     * button under either name is editable from the same tab.
     */
    public const OS_SCREENS = ['os_screen', 'download_software'];

    /**
     * Buy-screen callback fallbacks: which callback each button answers with
     * when no explicit button_key/action mapping exists (fork:
     * getBuyScreenButtons()).
     */
    private const BUY_FALLBACKS = [
        'renew_flow' => 'renew',
        'buy_new_flow' => 'group',
    ];

    /**
     * OS-picker keys mapped to the app_<os> callbacks the original hardcoded
     * keyboard used (fork: getDownloadScreenButtons()).
     */
    private const OS_KEYS = [
        'app_windows' => 'app_windows',
        'app_android' => 'app_android',
        'app_ios' => 'app_ios',
        'app_linux' => 'app_linux',
        'app_mac' => 'app_mac',
    ];

    /**
     * Every button of one screen, active and inactive, in display order.
     *
     * @return array<int, BotButton>
     */
    public function getAllButtons(string $screen = 'main_menu'): array
    {
        $query = BotButton::query()->orderBy('sort_order')->orderBy('row_num')->orderBy('col_num');

        if ($screen === 'main_menu') {
            // Rows saved before the screen column existed read as main-menu
            // buttons (fork: `screen = 'main_menu' OR screen IS NULL`).
            $query->where(fn ($q) => $q->where('screen', 'main_menu')->orWhereNull('screen'));
        } elseif (in_array($screen, self::OS_SCREENS, true)) {
            $query->whereIn('screen', self::OS_SCREENS);
        } else {
            $query->where('screen', $screen);
        }

        return $query->get()->all();
    }

    /**
     * Active buttons of one screen, ordered for keyboard building.
     *
     * @return array<int, BotButton>
     */
    public function getActiveButtons(string $screen = 'main_menu'): array
    {
        return array_values(array_filter(
            $this->getAllButtons($screen),
            fn (BotButton $button): bool => $button->is_active,
        ));
    }

    /**
     * The small inline keyboard shown right after tapping «خرید اشتراک».
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBuyScreenButtons(): array
    {
        return $this->screenButtons('buy_screen', self::BUY_FALLBACKS, 'buy');
    }

    /**
     * The OS-picker screen after tapping «دانلود نرم‌افزار». All five rows
     * share action_type 'open_url', so the button_key carries the callback.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOsScreenButtons(): array
    {
        return array_map(
            fn (array $entry): array => $entry + ['link_url' => $entry['link_url'] ?? null],
            $this->screenButtons(self::OS_SCREENS, self::OS_KEYS, 'apps', true),
        );
    }

    /**
     * Create or update one button by key. `default_label` is seeded once and
     * never overwritten, so the bot's text-matching always has the original
     * label to fall back on.
     *
     * @param  array<string, mixed>  $fields
     */
    public function upsert(string $buttonKey, array $fields): BotButton
    {
        $button = BotButton::query()->where('button_key', $buttonKey)->first();

        $attributes = [
            'current_label' => trim((string) ($fields['current_label'] ?? '')),
            'emoji' => trim((string) ($fields['emoji'] ?? '')),
            'is_active' => (bool) ($fields['is_active'] ?? true),
            'button_color' => $this->color($fields['button_color'] ?? 'none'),
            'custom_emoji_id' => trim((string) ($fields['custom_emoji_id'] ?? '')),
        ];

        if (array_key_exists('link_url', $fields)) {
            $attributes['link_url'] = trim((string) $fields['link_url']);
        }

        if (array_key_exists('hide_after_test', $fields) && $fields['hide_after_test'] !== null) {
            $attributes['hide_after_test'] = (bool) $fields['hide_after_test'];
        }

        if ($button === null) {
            return BotButton::query()->create($attributes + [
                'button_key' => $buttonKey,
                'default_label' => $attributes['current_label'],
                'screen' => (string) ($fields['screen'] ?? 'main_menu'),
                'action_type' => (string) ($fields['action_type'] ?? 'internal'),
                'sort_order' => (int) ($fields['sort_order'] ?? 0),
                'row_num' => (int) ($fields['row_num'] ?? 1),
                'col_num' => (int) ($fields['col_num'] ?? 1),
            ]);
        }

        // A blank label must never blank the keyboard: keep the old label,
        // exactly like the fork refusing an empty `current_label`.
        if ($attributes['current_label'] === '') {
            unset($attributes['current_label']);
        }

        $button->fill($attributes)->save();

        return $button;
    }

    /**
     * Flip one button's is_active flag.
     */
    public function toggle(string $buttonKey): BotButton
    {
        $button = BotButton::query()->where('button_key', $buttonKey)->firstOrFail();
        $button->fill(['is_active' => ! $button->is_active])->save();

        return $button;
    }

    /**
     * Persist a drag-and-drop layout: one entry per button, each carrying
     * its position. Also normalises the existing rows of the same screen so
     * the board always opens exactly as saved.
     *
     * @param  array<int, array{id?: int, button_key?: string, sort_order?: int, row_num?: int, col_num?: int}>  $rows
     */
    public function reorder(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $query = BotButton::query();

                if (! empty($row['id'])) {
                    $query->where('id', (int) $row['id']);
                } elseif (! empty($row['button_key'])) {
                    $query->where('button_key', (string) $row['button_key']);
                } else {
                    continue;
                }

                $query->update([
                    'sort_order' => max(0, (int) ($row['sort_order'] ?? 0)),
                    'row_num' => max(1, (int) ($row['row_num'] ?? 1)),
                    'col_num' => max(1, (int) ($row['col_num'] ?? 1)),
                ]);
            }
        });

        return true;
    }

    /**
     * The colour any button without its own colour renders with.
     *
     * 'blue' is the shipped default (fork read 'primary' as default, the
     * rewrite names the same colours after the Bot API values: blue/green/red
     * plus 'none' = plain Telegram look).
     */
    public function defaultColor(): string
    {
        $value = PanelSetting::query()->find('default_button_color')?->setting_value;

        return in_array($value, ['blue', 'green', 'red', 'none'], true) ? $value : 'blue';
    }

    /**
     * Store the default colour for buttons without an explicit colour.
     */
    public function saveDefaultColor(string $color): void
    {
        $color = in_array($color, ['blue', 'green', 'red', 'none'], true) ? $color : 'blue';

        PanelSetting::query()->updateOrInsert(
            ['setting_key' => 'default_button_color'],
            ['setting_value' => $color, 'updated_at' => now()],
        );
    }

    /**
     * A submitted colour reduced to the four values the fork's UI offered.
     * The fork stored 'primary'/'success'/'danger'; the rewrite accepts both
     * spellings and stores the Bot API names.
     */
    private function color(mixed $value): string
    {
        return match ((string) $value) {
            'blue', 'primary' => 'blue',
            'green', 'success' => 'green',
            'red', 'danger' => 'red',
            default => 'none',
        };
    }

    /**
     * Active buttons of one screen, shaped for inline keyboards. The
     * callback is resolved in the fork's order: button_key map, then the
     * button_key itself, then the screen fallback.
     *
     * @param  list<string>|string  $screens
     * @param  array<string, string>  $keyMap
     * @return array<int, array<string, mixed>>
     */
    private function screenButtons(string|array $screens, array $keyMap, string $fallback, bool $withLink = false): array
    {
        $screens = (array) $screens;

        $query = BotButton::query()
            ->whereIn('screen', $screens)
            ->where('is_active', true)
            ->orderBy('sort_order');

        if (count($screens) > 1) {
            $query->orderBy('row_num');
        }

        $out = [];

        foreach ($query->get() as $button) {
            $emoji = $button->emoji !== '' ? trim($button->emoji).' | ' : '';

            $entry = [
                'button_key' => $button->button_key,
                'action_type' => $button->action_type,
                'text' => $emoji.$button->current_label,
                'callback_data' => $keyMap[$button->button_key]
                    ?? ($button->button_key !== '' ? $button->button_key : $fallback),
                'style' => in_array($button->button_color, ['blue', 'green', 'red'], true) ? $button->button_color : null,
                'icon_custom_emoji_id' => $button->custom_emoji_id !== '' ? $button->custom_emoji_id : null,
            ];

            if ($withLink) {
                $entry['link_url'] = $button->link_url;
            }

            $out[] = $entry;
        }

        return $out;
    }
}
