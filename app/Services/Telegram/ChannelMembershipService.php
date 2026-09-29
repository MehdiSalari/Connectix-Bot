<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Exceptions\TelegramApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Forced channel membership.
 *
 * Port of `checkUserChannelJoin()`. The Telegram answer is cached for a short
 * period so a user tapping through several menus does not trigger an API call
 * per keystroke, and a transport failure is treated as "joined" so a Telegram
 * outage cannot lock every user out of the bot.
 */
class ChannelMembershipService
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly TelegramService $telegram,
    ) {
    }

    /**
     * Whether the enforcement is switched on for this installation.
     */
    public function isEnforced(): bool
    {
        return (bool) config('echovpn.force_channel_join', false);
    }

    /**
     * Whether the user is currently a member of the required channel.
     */
    public function hasJoined(int|string $chatId, int|string $userId): bool
    {
        $channelId = config('echovpn.telegram_channel_id');

        if (blank($channelId)) {
            Log::warning('Channel membership is enforced but no channel id is configured.');

            return true;
        }

        $cacheKey = 'echovpn.channel_join.'.$channelId.'.'.$userId;

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $joined = $this->telegram->isChatMember($channelId, $userId);
        } catch (TelegramApiException $e) {
            // Fail open: an unreachable Telegram must not lock users out.
            Log::warning('Channel membership check failed, assuming joined.', [
                'user_id' => (string) $userId,
                'error' => $e->getMessage(),
            ]);

            return true;
        }

        Cache::put($cacheKey, $joined, self::CACHE_TTL_SECONDS);

        return $joined;
    }

    /**
     * Forget a cached answer, e.g. when a user taps "I joined" again.
     */
    public function forget(int|string $userId): void
    {
        $channelId = config('echovpn.telegram_channel_id');

        if (blank($channelId)) {
            return;
        }

        Cache::forget('echovpn.channel_join.'.$channelId.'.'.$userId);
    }
}
