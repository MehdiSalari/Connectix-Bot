<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Models\PanelSetting;

/**
 * The /start emoji (ایموجی ابتدای /start) settings.
 *
 * Port of the fork's StartEmojiService, minus the send path (the bot layer
 * calls render() when it handles /start). Stored in `panel_settings` under
 * `start_emoji_*` keys, mirroring the fork's single JSON blob in bot_config.
 */
class StartEmojiAdminService
{
    /** @return array{enabled: bool, emoji_char: string, custom_emoji_id: string} */
    public static function get(): array
    {
        $custom = preg_replace('/\D+/', '', (string) self::read('start_emoji_custom_emoji_id'));
        $char = trim((string) self::read('start_emoji_emoji_char'));

        return [
            'enabled' => self::read('start_emoji_enabled') === '1',
            'emoji_char' => $char !== '' ? mb_substr($char, 0, 8) : '👋',
            'custom_emoji_id' => (string) $custom,
        ];
    }

    public static function save(array $in): bool
    {
        $custom = preg_replace('/\D+/', '', (string) ($in['custom_emoji_id'] ?? ''));
        $char = trim((string) ($in['emoji_char'] ?? ''));

        self::write('start_emoji_enabled', ! empty($in['enabled']) ? '1' : '0');
        self::write('start_emoji_custom_emoji_id', (string) $custom);
        self::write('start_emoji_emoji_char', $char !== '' ? mb_substr($char, 0, 8) : '👋');

        return true;
    }

    /** HTML for one message body (premium via <tg-emoji>, else the plain char). */
    public static function render(?array $c = null): string
    {
        $c = $c ?? self::get();
        $char = htmlspecialchars($c['emoji_char'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($c['custom_emoji_id'] !== '') {
            return '<tg-emoji emoji-id="'.$c['custom_emoji_id'].'">'.$char.'</tg-emoji>';
        }

        return $char;
    }

    private static function read(string $key): ?string
    {
        return PanelSetting::query()->where('setting_key', $key)->value('setting_value');
    }

    private static function write(string $key, string $value): void
    {
        PanelSetting::query()->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $value]
        );
    }
}
