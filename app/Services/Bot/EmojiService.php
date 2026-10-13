<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\BotEmoji;
use App\Models\EmojiPackImported;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The central emoji registry and the imported premium-emoji packs.
 *
 * Port of the feature fork's `services/EmojiService.php` plus the picker
 * half of `services/EmojiPackImportService.php`. `emoji_key` is the logical
 * name the bot's code references (menu_buy, msg_success, …), `emoji_char`
 * the fallback glyph and `custom_emoji_id` the premium Telegram emoji that
 * overrides it.
 */
class EmojiService
{
    /**
     * Sections the admin page tabs over, in the fork's display order. The
     * fork groups whatever sections exist in the table; these are the ones
     * its seed list writes (buttons/messages/plans/payment/support/reseller
     * plus the free-form «custom» the add-key modal suggests).
     */
    public const SECTIONS = [
        'buttons' => 'دکمه‌ها',
        'messages' => 'پیام‌ها',
        'plans' => 'پلن‌ها',
        'payment' => 'پرداخت',
        'reseller' => 'نمایندگی',
        'support' => 'پشتیبانی',
        'custom' => 'سفارشی',
    ];

    /**
     * Every emoji, newest edit last.
     *
     * @return array<int, BotEmoji>
     */
    public function all(): array
    {
        return BotEmoji::query()->orderBy('id')->get()->all();
    }

    /**
     * Emojis grouped by section, ready for the sections tabs. An emoji with
     * an unknown/empty section reads as «سفارشی» — the fork bucketed
     * anything unrecognised under 'general'; the rewrite keeps one bucket
     * and names it the same way the add-form labels it.
     *
     * @return array<string, array<int, BotEmoji>>
     */
    public function sections(): array
    {
        $out = [];

        foreach ($this->all() as $emoji) {
            $section = $emoji->section !== '' ? $emoji->section : 'custom';
            $out[$section][] = $emoji;
        }

        return $out;
    }

    /**
     * Create or update one emoji by key.
     */
    public function upsert(string $emojiKey, string $name, string $section, string $emojiChar, string $customEmojiId = '', string $description = ''): BotEmoji
    {
        $emojiKey = trim($emojiKey);
        $emojiChar = trim($emojiChar);
        $section = trim($section) !== '' ? trim($section) : 'custom';

        if ($emojiKey === '' || $emojiChar === '') {
            throw new \InvalidArgumentException('کلید و ایموجی الزامی هستند.');
        }

        $emoji = BotEmoji::query()->where('emoji_key', $emojiKey)->first();

        $attributes = [
            'name' => trim($name) !== '' ? trim($name) : $emojiKey,
            'section' => $section,
            'emoji_char' => $emojiChar,
            'custom_emoji_id' => trim($customEmojiId),
            'description' => trim($description),
            'updated_at' => Carbon::now(),
        ];

        if ($emoji === null) {
            return BotEmoji::query()->create(['emoji_key' => $emojiKey] + $attributes);
        }

        $emoji->fill($attributes)->save();

        return $emoji;
    }

    /**
     * Remove one emoji by key. A bot_emojis key is the only thing the bot
     * reads, so deleting falls back to the code-side default glyph.
     */
    public function delete(string $emojiKey): bool
    {
        return BotEmoji::query()->where('emoji_key', $emojiKey)->delete() > 0;
    }

    /**
     * Star / unstar one imported emoji. Starred ones sort to the top of
     * their pack (fork: toggleFavorite()).
     */
    public function toggleFavorite(string $customEmojiId): ?bool
    {
        $pack = EmojiPackImported::query()->where('custom_emoji_id', $customEmojiId)->first();

        if ($pack === null) {
            return null;
        }

        $pack->fill(['is_favorite' => ! $pack->is_favorite])->save();

        return (bool) $pack->is_favorite;
    }

    /**
     * Insert one emoji into the picker list of imported premium packs.
     *
     * The fork filled this table from a Telegram sticker-set fetch; here the
     * admin types the values in (or the wave-4 bot flow calls this on a
     * premium-emoji message). The unique custom_emoji_id makes a re-import
     * of the same id refresh the row instead of duplicating it.
     */
    public function importPack(string $setName, string $customEmojiId, string $emojiChar): EmojiPackImported
    {
        $setName = trim($setName);
        $customEmojiId = trim($customEmojiId);
        $emojiChar = trim($emojiChar) !== '' ? trim($emojiChar) : '❓';

        if ($setName === '' || $customEmojiId === '') {
            throw new \InvalidArgumentException('نام پک و شناسه ایموجی الزامی هستند.');
        }

        return DB::transaction(function () use ($setName, $customEmojiId, $emojiChar): EmojiPackImported {
            $existing = EmojiPackImported::query()->where('custom_emoji_id', $customEmojiId)->first();

            if ($existing !== null) {
                $existing->fill([
                    'set_name' => $setName,
                    'emoji_char' => $emojiChar,
                    'imported_at' => Carbon::now(),
                ])->save();

                return $existing;
            }

            return EmojiPackImported::query()->create([
                'set_name' => $setName,
                'custom_emoji_id' => $customEmojiId,
                'emoji_char' => $emojiChar,
                'imported_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Distinct imported pack names with their emoji count, newest import
     * first (fork: listPacks()).
     *
     * @return array<int, array{set_name: string, cnt: int}>
     */
    public function packList(?string $setName = null): array
    {
        $query = EmojiPackImported::query()
            ->selectRaw('set_name, COUNT(*) as cnt')
            ->groupBy('set_name')
            ->orderByRaw('MAX(imported_at) DESC');

        if ($setName !== null && $setName !== '') {
            $query->where('set_name', $setName);
        }

        return $query->get()
            ->map(fn ($row): array => ['set_name' => (string) $row->set_name, 'cnt' => (int) $row->cnt])
            ->all();
    }

    /**
     * One pack's emojis, favourites first (fork: getPackEmojis()).
     *
     * @return array<int, EmojiPackImported>
     */
    public function packEmojis(string $setName): array
    {
        return EmojiPackImported::query()
            ->where('set_name', $setName)
            ->orderByDesc('is_favorite')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Remove one emoji from the picker (not the whole pack). A bot_emojis
     * key that already carries this custom_emoji_id keeps working.
     */
    public function deleteFromPack(string $customEmojiId): bool
    {
        return EmojiPackImported::query()->where('custom_emoji_id', $customEmojiId)->delete() > 0;
    }

    /**
     * Remove a whole imported pack from the picker list. Never touches
     * bot_emojis; re-sending any emoji of the pack re-imports it later.
     */
    public function deletePack(string $setName): bool
    {
        return EmojiPackImported::query()->where('set_name', $setName)->delete() > 0;
    }
}
