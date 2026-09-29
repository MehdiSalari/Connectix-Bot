<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Download\DownloadLinkService;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

/**
 * The app download menus.
 *
 * Port of the `apps` branch of bot.php and the legacy `app()` helper: the
 * system picker lists the platforms, and each platform shows the download
 * links scraped from the Connectix landing page plus a Telegram channel
 * shortcut wherever legacy offered one.
 */
class DownloadHandler implements UpdateHandler
{
    private const MENU = 'apps';

    private const MENU_PREFIX = 'app_';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly DownloadLinkService $downloads,
        private readonly MessageFactory $messages,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();

        if ($data === null) {
            return false;
        }

        foreach (DownloadLinkService::PLATFORMS as $platform) {
            if ($data === self::MENU_PREFIX.$platform) {
                return true;
            }
        }

        return $data === self::MENU;
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = (string) $update->callbackData();

        if ($data === self::MENU) {
            $this->showPlatforms($update, $user);

            return;
        }

        $this->showLinks($update, $user, substr($data, strlen(self::MENU_PREFIX)));
    }

    // -----------------------------------------------------------------
    // Menus
    // -----------------------------------------------------------------

    /**
     * The operating system picker, port of `keyboard('apps')`.
     */
    private function showPlatforms(TelegramUpdate $update, User $user): void
    {
        $rows = [
            [
                ['text' => '📱 | آیفون (iOS)', 'callback_data' => 'app_ios'],
                ['text' => '🤖 | اندروید', 'callback_data' => 'app_android'],
            ],
            [
                ['text' => '🖥 | مک', 'callback_data' => 'app_mac'],
                ['text' => '💻 | ویندوز', 'callback_data' => 'app_windows'],
            ],
            [
                ['text' => '🐧 | لینوکس (Debian)', 'callback_data' => 'app_linux'],
            ],
            [['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']],
        ];

        $this->render($update, $user, $this->messages->make('apps'), $rows);
    }

    /**
     * The download links of one platform, port of `app()`.
     */
    private function showLinks(TelegramUpdate $update, User $user, string $platform): void
    {
        $links = $this->downloads->links($platform);

        $text = "دانلود اپلیکیشن Connectix برای <b>{$this->platformLabel($platform)}</b>\n\n"
            .'برای دانلود از دکمه های زیر استفاده کنید.';

        $rows = [];

        foreach ($links as $link) {
            if (($link['label'] ?? '') === '' || ($link['url'] ?? '') === '') {
                continue;
            }

            $rows[] = [[
                'text' => '📥 | '.str_replace('Download', 'دانلود مستقیم', $link['label']),
                'url' => $link['url'],
            ]];
        }

        $directChannelId = $this->channelIdFor($platform);

        if ($directChannelId !== null) {
            $rows[] = [[
                'text' => '📲 | دانلود از تلگرام',
                'url' => 'https://t.me/'.$this->channelUsername().'/'.$directChannelId,
            ]];
        }

        $rows[] = [
            ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
            ['text' => '↪️ | بازگشت', 'callback_data' => 'apps'],
        ];

        $this->render($update, $user, $text, $rows);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function platformLabel(string $platform): string
    {
        return match ($platform) {
            'android' => 'اندروید 🤖',
            'ios' => 'آیفون (iOS) 📱',
            'windows' => 'ویندوز 💻',
            'mac' => 'مک 🖥',
            'linux' => 'لینوکس 🐧',
            default => 'نامشخص',
        };
    }

    /**
     * The post id of the platform's Telegram channel message, which legacy
     * hardcoded for every platform except iOS and Linux.
     */
    private function channelIdFor(string $platform): ?string
    {
        if ($platform === 'ios' || $platform === 'linux') {
            return null;
        }

        return match ($platform) {
            'android' => '4',
            'windows' => '5',
            'mac' => '11',
            default => '',
        };
    }

    private function channelUsername(): string
    {
        return (string) config('connectix_bot.telegram_app_username', 'connectixapp');
    }

    /**
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
