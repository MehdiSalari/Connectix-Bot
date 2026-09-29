<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Exceptions\TelegramApiException;
use App\Models\User;
use App\Services\Panel\PanelSettingsService;
use App\Services\User\UserService;
use App\Services\User\UserStateService;
use App\Telegram\HandlerRegistry;
use App\Telegram\TelegramUpdate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Entry point for every incoming Telegram update.
 *
 * Replaces the ~600 line procedural bot.php. The gateway owns exactly four
 * concerns, in the same order legacy applied them:
 *
 *   1. the bot is switched on,
 *   2. the user is a member of the required channel,
 *   3. the user row exists and is in sync,
 *   4. the first handler that claims the update runs.
 *
 * Everything else lives in a handler. All failures are contained here: a
 * webhook must always be acknowledged or Telegram retries it in a tight loop.
 */
class TelegramGateway
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly UserService $users,
        private readonly UserStateService $states,
        private readonly ChannelMembershipService $channels,
        private readonly HandlerRegistry $handlers,
        private readonly PanelSettingsService $settings,
    ) {}

    public function handle(TelegramUpdate $update): void
    {
        $chatId = $update->chatId();

        if ($chatId === null) {
            // Nothing can be answered without a chat to answer in.
            return;
        }

        if (! config('connectix_bot.active', true)) {
            $this->refuse($update, $chatId, 'ربات در حال حاضر غیرفعال است.');

            return;
        }

        if (! $this->passesChannelGate($update, $chatId)) {
            return;
        }

        $user = $this->users->sync(
            $this->states,
            $chatId,
            $update->username(),
            $update->firstName(),
        );

        if ($user === null) {
            $this->refuse($update, $chatId, 'خطا در بارگذاری اطلاعات کاربر. لطفاً دوباره تلاش کنید.');

            return;
        }

        $this->dispatch($update, $user);
    }

    /**
     * Run the first handler that claims the update.
     */
    private function dispatch(TelegramUpdate $update, User $user): void
    {
        $handler = $this->handlers->firstSupporting($update, $user);

        if ($handler === null) {
            // Legacy ignored updates it had no branch for. A callback query with
            // no handler is answered so the button stops spinning.
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'این گزینه منقضی شده است.',
            );

            return;
        }

        try {
            $handler->handle($update, $user);
        } catch (TelegramApiException $e) {
            // The handler already logged the API level detail; here we only add
            // the context needed to find the offending update.
            Log::error('Telegram handler failed while talking to the API.', [
                'handler' => $handler::class,
                'update_id' => $update->updateId(),
                'chat_id' => $update->chatId(),
                'error' => $e->getMessage(),
            ]);

            $this->telegram->answerCallbackQueryQuietly($update->callbackId(), 'خطایی رخ داد. دوباره تلاش کنید.');
        } catch (Throwable $e) {
            Log::error('Telegram handler failed.', [
                'handler' => $handler::class,
                'update_id' => $update->updateId(),
                'chat_id' => $update->chatId(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $this->telegram->answerCallbackQueryQuietly($update->callbackId(), 'خطایی رخ داد. دوباره تلاش کنید.');

            $this->refuse($update, (string) $user->chat_id, 'خطایی رخ داد. لطفاً دوباره تلاش کنید.');
        }
    }

    /**
     * Enforce channel membership when the feature is enabled.
     */
    private function passesChannelGate(TelegramUpdate $update, string $chatId): bool
    {
        if (! $this->channels->isEnforced()) {
            return true;
        }

        $userId = $update->fromUserId();

        if ($userId === null) {
            return true;
        }

        if ($this->channels->hasJoined($chatId, $userId)) {
            return true;
        }

        // The user may be retrying after joining; never trust a cached "no".
        $this->channels->forget($userId);

        if ($this->channels->hasJoined($chatId, $userId)) {
            return true;
        }

        $this->refuse($update, $chatId, 'برای استفاده از ربات باید در کانال ما عضو شوید.', true);

        return false;
    }

    /**
     * Tell the user why nothing happened.
     *
     * @param  bool  $joinAction  Add the "join the channel" button.
     */
    private function refuse(TelegramUpdate $update, string $chatId, string $text, bool $joinAction = false): void
    {
        $params = [];

        if ($joinAction) {
            $channel = $this->settings->channelTelegram();

            $keyboard = [];

            if ($channel !== '') {
                $keyboard[] = [[
                    'text' => 'عضویت در کانال',
                    'url' => 'https://t.me/'.$channel,
                ]];
            }

            $keyboard[] = [[
                'text' => '🔄 | بررسی مجدد',
                'callback_data' => 'check_join',
            ]];

            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard,
            ], JSON_UNESCAPED_UNICODE);
        }

        try {
            $this->telegram->sendMessage($chatId, $text, $params);
        } catch (TelegramApiException $e) {
            Log::warning('Could not deliver a refusal message to the user.', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
