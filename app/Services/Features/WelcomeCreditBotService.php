<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Services\Telegram\TelegramService;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper exposing the welcome credit grant as a bot-facing service.
 *
 * The actual policy and wallet write live in WelcomeGiftService (already
 * implemented in this codebase). This class just delegates and provides a
 * stable interface for handlers/other bot services.
 */
class WelcomeCreditBotService
{
    /**
     * Grant the welcome credit if the campaign is eligible for this chat.
     *
     * Returns true only when a credit was actually granted.
     */
    public static function grantIfEligible(string|int $chatId): bool
    {
        try {
            return WelcomeGiftService::grantIfEligible($chatId);
        } catch (\Throwable $e) {
            Log::warning('WelcomeCreditBotService::grantIfEligible failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Notify a user about the welcome credit (used if the caller wants to
     * resend the notice). This delegates to TelegramService directly.
     */
    public static function notify(int|string $chatId, string $text): bool
    {
        try {
            $res = app(TelegramService::class)->sendMessage((string) $chatId, $text, ['parse_mode' => 'HTML']);

            return (bool) ($res['ok'] ?? false);
        } catch (\Throwable $e) {
            Log::warning('WelcomeCreditBotService::notify failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
