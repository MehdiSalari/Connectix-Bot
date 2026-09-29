<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Exceptions\TelegramApiException;
use App\Models\User;
use App\Services\Guide\GuideService;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Support\Facades\Log;

/**
 * The guides menu and its callbacks.
 *
 * Port of the `guide` branch of bot.php and the legacy `guide()` helper: the
 * menu opens as a fresh message, platform installs show a system picker, and
 * an action resolves to either a link button or a locally stored video that is
 * uploaded and replaces the menu message.
 *
 * The videos are sent and their source messages deleted directly from here
 * because they are side effects, not menus; link and platform pages are
 * rendered as edits, exactly like legacy (`callBackCheck()` edited the message
 * the button sat on).
 */
class GuideHandler implements UpdateHandler
{
    private const MENU = 'guide';

    private const MENU_PREFIX = 'guide_';

    private const INSTALL = 'guide_install';

    /** Shown when no link file and no video exists for an action. */
    private const NO_CONTENT = '🙅🏻 فعلا ویدیو آموزشی در دسترس نمی باشد!';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly GuideService $guide,
        private readonly MessageFactory $messages,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();

        if ($data === null) {
            return false;
        }

        if ($data === self::MENU || $data === self::INSTALL) {
            return true;
        }

        foreach (GuideService::PLATFORMS as $platform) {
            if ($data === self::MENU_PREFIX.$platform) {
                return true;
            }
        }

        return str_starts_with($data, self::MENU_PREFIX.'custom_');
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = (string) $update->callbackData();

        if ($data === self::MENU) {
            $this->showMenu($update, $user);

            return;
        }

        $this->runAction($update, $user, substr($data, strlen(self::MENU_PREFIX)));
    }

    // -----------------------------------------------------------------
    // Menu
    // -----------------------------------------------------------------

    /**
     * The guides menu, sent as a fresh message over the callback one.
     *
     * Port of the `guide` branch of bot.php: a new message is sent and the one
     * the button sat on is deleted, so the chat does not accumulate copies.
     */
    private function showMenu(TelegramUpdate $update, User $user): void
    {
        $chatId = (string) $user->chat_id;

        $rows = [
            [$this->guide->button('📲 | آموزش استفاده از نرم افزار', 'use')],
            [['text' => '⚙ | آموزش نصب نرم افزار', 'callback_data' => self::INSTALL]],
        ];

        foreach ($this->guide->customItems() as $index => $item) {
            $rows[] = [($item['type'] ?? '') === 'link'
                ? ['text' => "🎬 | {$item['title']}", 'url' => (string) ($item['url'] ?? '')]
                : ['text' => "🎬 | {$item['title']}", 'callback_data' => self::MENU_PREFIX.'custom_'.$index]];
        }

        $rows[] = [['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']];

        $this->telegram->sendMessage($chatId, $this->messages->make('guide'), [
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE),
        ]);

        $messageId = $update->callbackMessageId();

        if ($messageId !== null) {
            $this->deleteMessage($chatId, $messageId);
        }
    }

    // -----------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------

    /**
     * Resolve one guide action, port of `guide()`.
     */
    private function runAction(TelegramUpdate $update, User $user, string $action): void
    {
        $custom = $this->guide->parseCustomAction($action);

        if ($custom !== null) {
            $play = $this->guide->customVideo($custom);

            $path = $play !== null ? ($play['path'] ?? null) : null;

            $this->playVideo($update, $user, is_string($path) ? $path : null);

            return;
        }

        if ($action === 'install') {
            $this->showInstallMenu($update, $user);

            return;
        }

        $this->showTeaching($update, $user, $action);
    }

    /**
     * The operating system picker, port of the `install` branch of `guide()`.
     * The buttons prefer a direct link when the seller shipped one.
     */
    private function showInstallMenu(TelegramUpdate $update, User $user): void
    {
        $rows = [
            [
                $this->guide->button('📱 | آیفون (iOS)', 'ios'),
                $this->guide->button('🤖 | اندروید', 'android'),
            ],
            [
                $this->guide->button('🖥 | مک', 'mac'),
                $this->guide->button('💻 | ویندوز', 'windows'),
            ],
            [
                $this->guide->button('🐧 | لینوکس (Debian)', 'linux'),
            ],
            [['text' => '↪️ | بازگشت', 'callback_data' => 'guide']],
        ];

        $this->render($update, $user, '⚙ سیستم عامل مورد نظر را انتخاب نمایید:', $rows);
    }

    /**
     * The "how do I use this" page for an action: a link when the seller
     * provided one, otherwise the locally uploaded video.
     *
     * Port of the `use` and default branches of `guide()`.
     */
    private function showTeaching(TelegramUpdate $update, User $user, string $action): void
    {
        $link = $this->guide->linkFor($action);

        if ($link !== null) {
            $this->render($update, $user, 'برای مشاهده آموزش از دکمه زیر استفاده کنید.', [
                [['text' => '🎬 | مشاهده آموزش', 'url' => $link]],
                [['text' => '↪️ | بازگشت', 'callback_data' => 'guide']],
            ]);

            return;
        }

        $this->playVideo($update, $user, $this->guide->videoFor($action));
    }

    /**
     * Upload a guide video and remove the message the button was on.
     *
     * Legacy answered with an alert and did nothing else when the video was
     * missing, unreadable or refused by the path guard.
     */
    private function playVideo(TelegramUpdate $update, User $user, ?string $path): void
    {
        if (! is_string($path) || $path === '') {
            $this->telegram->answerCallbackQueryQuietly($update->callbackId(), self::NO_CONTENT);

            return;
        }

        $chatId = (string) $user->chat_id;

        try {
            $this->telegram->sendVideo($chatId, $path, [
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '↪️ | بازگشت', 'callback_data' => 'guide']],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (TelegramApiException $e) {
            Log::error('Guide video could not be sent.', [
                'chat_id' => $chatId,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $messageId = $update->callbackMessageId();

        if ($messageId !== null) {
            $this->deleteMessage($chatId, $messageId);
        }
    }

    /**
     * Deleting the source message is cosmetic: a refusal here must not break
     * the video that was already delivered.
     */
    private function deleteMessage(string $chatId, int $messageId): void
    {
        try {
            $this->telegram->deleteMessage($chatId, $messageId);
        } catch (TelegramApiException $e) {
            Log::warning('Guide source message could not be deleted.', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Edit the callback message, port of what the default branch of bot.php did
     * with the arrays `guide()` returned.
     *
     * @param  array<int, array<int, array<string, string>>>  $rows
     */
    private function render(TelegramUpdate $update, User $user, string $text, array $rows): void
    {
        $chatId = (string) $user->chat_id;

        $keyboard = json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);

        $messageId = $update->callbackMessageId();

        if ($messageId !== null) {
            $this->telegram->editMessageText($chatId, $messageId, $text, [
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard,
            ]);

            return;
        }

        $this->telegram->sendMessage($chatId, $text, [
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard,
        ]);
    }
}
