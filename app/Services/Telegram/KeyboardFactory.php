<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Panel\PanelSettingsService;
use App\Services\Plan\PlanService;
use App\Services\User\UserService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inline keyboards. Port of the legacy `keyboard()` switch.
 *
 * Buttons are emitted in exactly the legacy order because the layout is part
 * of the product: users have muscle memory for which button sits where. Each
 * builder returns a nested array of rows, ready to hand to
 * {@see TelegramService::sendMessage()} as a `reply_markup`.
 */
class KeyboardFactory
{
    public function __construct(
        private readonly PanelSettingsService $settings,
        private readonly PlanService $plans,
        private readonly UserService $users,
        private readonly AdminGuard $admins,
    ) {}

    /**
     * The home screen.
     *
     * Legacy put a conditional first row on top: the free trial offer while
     * the user has not taken one, or a "keep the same one" shortcut once they
     * have.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function mainMenu(string|int $chatId): array
    {
        $rows = $this->firstRow($chatId);

        $rows[] = [
            ['text' => '📦 | اکانت های من', 'callback_data' => 'accounts'],
            ['text' => '🛍️ | خرید / تمدید اکانت ', 'callback_data' => 'action:buy_or_renew_service'],
        ];

        $rows[] = [
            ['text' => '📲 | دانلود نرم افزار', 'callback_data' => 'apps'],
            ['text' => '💡 | آموزش ها', 'callback_data' => 'guide'],
        ];

        $rows[] = [
            ['text' => '💁🏻‍♂️ | پشتیبانی', 'callback_data' => 'support'],
            ['text' => '❓ | سوالات متداول', 'callback_data' => 'faq'],
        ];

        $rows[] = [
            ['text' => '👝 |  کیف پول', 'callback_data' => 'wallet'],
        ];

        $panel = $this->panelButton($chatId);

        if ($panel !== null) {
            $rows[] = [$panel];
        }

        $channel = $this->settings->channelTelegram();

        if ($channel !== '') {
            $rows[] = [
                ['text' => '📣 | اخبار و اطلاعیه ها', 'url' => 'https://t.me/'.$channel],
            ];
        }

        return $rows;
    }

    /**
     * The trial or "stick with the current one" row, if it applies.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function firstRow(string|int $chatId): array
    {
        $user = $this->users->findByChatId($chatId);

        if ($user === null) {
            return [];
        }

        if ($this->settings->flag('connectix_bot.test_enabled', 'test', true) && ! $user->hasUsedTest() && $this->hasFreeTestPlan()) {
            return [[['text' => '🎁 | دریافت اکانت تست', 'callback_data' => 'get_test']]];
        }

        if ($user->hasUsedTest()) {
            return [[['text' => '🙋🏻 | همون همیشگی', 'callback_data' => 'always_select:0']]];
        }

        return [];
    }

    /**
     * The trial offer is only shown when the panel actually has a free plan.
     */
    private function hasFreeTestPlan(): bool
    {
        try {
            return $this->plans->freeTrialPlans() !== [];
        } catch (Throwable $e) {
            Log::warning('Could not check for free plans, hiding the trial button.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Admins get the management panel; everyone else gets their profile.
     *
     * Legacy always emitted this row and derived its URL by swapping bot.php
     * for app.php in the current URL. The Laravel webhook lives at
     * /telegram/webhook and legacy app.php was never ported, so the derived
     * URL is not a WebApp and Telegram rejects the whole message. The row is
     * therefore emitted only while an operator has configured
     * CONNECTIX_BOT_WEBAPP_URL.
     *
     * @return array<string, mixed>|null
     */
    private function panelButton(string|int $chatId): ?array
    {
        $url = $this->settings->webAppUrl();

        if ($url === null || $url === '') {
            return null;
        }

        $button = [
            'web_app' => ['url' => $url],
        ];

        if ($this->admins->isAdmin($chatId)) {
            return ['text' => '👨🏻‍💻 | پنل مدیریت'] + $button;
        }

        return ['text' => '👤 | پروفایل'] + $button;
    }

    /**
     * Shown to a user who has not joined the required channel.
     *
     * @return array<string, mixed>
     */
    public function joinChannel(): array
    {
        $rows = [];
        $channel = $this->settings->channelTelegram();

        if ($channel !== '') {
            $rows[] = [[
                'text' => '🔗 | عضویت در کانال',
                'url' => 'https://t.me/'.$channel,
            ]];
        }

        $rows[] = [[
            'text' => '✅ | بررسی عضویت',
            'callback_data' => 'main_menu',
        ]];

        return ['inline_keyboard' => $rows];
    }
}
