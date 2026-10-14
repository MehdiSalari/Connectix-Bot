<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\PanelSetting;

/**
 * Button color resolution, ported from the fork's `autoButtonColor()` in
 * `functions.php` + the admin's default_button_color setting.
 *
 * Rules (in order):
 * 1. Explicit color on the button ('blue'|'green'|'red') always wins.
 * 2. Auto green: label starts with '✅'/'✔' OR contains 'تایید'/'پرداخت'.
 * 3. Auto red:   label starts with '❌'/'✖'/'🗑'/'⛔' OR contains 'انصراف'/'لغو'/'حذف'.
 * 4. Default from panel_settings.default_button_color ('blue'|'green'|'red'|'none').
 * 5. 'none' → null (no style field sent = Telegram plain look).
 */
class ButtonColorService
{
    /** @var string|null cached default color per process */
    private static ?string $cachedDefault = null;

    /**
     * Resolve the final style value for a button.
     *
     * @param  string  $label  Button text as shown to the user
     * @param  string|null  $explicit  Button's own color ('blue'|'green'|'red'|'none'|null)
     * @return string|null Telegram 'style' value ('primary'|'success'|'danger') or null
     */
    public static function colorFor(string $label, ?string $explicit = null): ?string
    {
        // 1. Explicit color on the button always wins
        if ($explicit !== null && $explicit !== '' && $explicit !== 'none') {
            return self::mapToTelegramStyle($explicit);
        }

        // 2. Auto-detect from label
        $auto = self::autoColor($label);
        if ($auto !== null) {
            return $auto;
        }

        // 3. Fall back to panel default
        $default = self::defaultColor();

        return $default !== null ? self::mapToTelegramStyle($default) : null;
    }

    /**
     * Fork's autoButtonColor() logic.
     *
     * @return string|null 'success'|'danger' or null
     */
    private static function autoColor(string $label): ?string
    {
        $t = ltrim($label);

        if ($t === '') {
            return null;
        }

        // Red / danger: ❌ / ✖ / 🗑 / ⛔ at start, or cancel/delete words
        if (preg_match('/^(❌|✖|🗑|⛔)/u', $t)
            || preg_match('/انصراف|لغو|حذف/u', $t)
        ) {
            return 'danger';
        }

        // Green / success: ✅ / ✔ at start, or confirm/pay words
        if (preg_match('/^(✅|✔)/u', $t)
            || preg_match('/تایید|پرداخت/u', $t)
        ) {
            return 'success';
        }

        return null;
    }

    /**
     * Get the default color from panel_settings (cached per process).
     *
     * @return string|null 'blue'|'green'|'red'|'none' or null if 'none'
     */
    private static function defaultColor(): ?string
    {
        if (self::$cachedDefault !== null) {
            return self::$cachedDefault;
        }

        $value = PanelSetting::query()
            ->where('setting_key', 'default_button_color')
            ->value('setting_value');

        $allowed = ['blue', 'green', 'red', 'none'];
        self::$cachedDefault = in_array($value, $allowed, true) ? $value : 'blue';

        return self::$cachedDefault === 'none' ? null : self::$cachedDefault;
    }

    /**
     * Map our internal color names to Bot API 9.4 style values.
     */
    private static function mapToTelegramStyle(string $color): string
    {
        return match ($color) {
            'blue', 'primary' => 'primary',
            'green', 'success' => 'success',
            'red', 'danger' => 'danger',
            default => 'primary',
        };
    }

    /** Clear the process cache (for tests). */
    public static function clearCache(): void
    {
        self::$cachedDefault = null;
    }
}
