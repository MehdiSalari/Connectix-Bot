<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\GatewayOrder;
use App\Models\User;
use App\Services\Gateways\OnlineGatewayService;
use App\Services\Panel\PanelSettingsService;
use App\Services\Telegram\AdminGuard;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

class GatewayHandler implements UpdateHandler
{
    private const CALLBACK_PREFIX = 'gw_';

    private const FORBIDDEN = '⛔ دسترسی غیرمجاز.';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly UserStateService $state,
        private readonly OnlineGatewayService $gateways,
        private readonly PanelSettingsService $settings,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();

        if ($data !== null) {
            return str_starts_with($data, self::CALLBACK_PREFIX);
        }

        return false;
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = $update->callbackData();
        if ($data === null) {
            return;
        }

        $query = substr($data, strlen(self::CALLBACK_PREFIX));
        $parts = explode(':', $query);
        $action = $parts[0] ?? '';

        switch ($action) {
            case 'go':
                $this->createOrder($update, $user, $parts[1] ?? '', $parts[2] ?? 'buy');

                return;
            case 'chk':
                $this->checkOrder($update, $user, (int) ($parts[1] ?? 0));

                return;
            case 'cancel':
                $this->cancelOrder($update, $user, (int) ($parts[1] ?? 0));

                return;
        }
    }

    private function createOrder(TelegramUpdate $update, User $user, string $driver, string $mode): void
    {
        $result = $this->gateways->createOrder(
            chatId: (string) $user->chat_id,
            driver: $driver,
            mode: $mode,
            state: $this->state->get($user) ?? [],
        );

        if (! $result['ok']) {
            $this->render($update, $user, $result['text'], $result['kb'] ?? []);

            return;
        }

        $order = $result['order'];
        $screen = $this->gateways->orderScreen($order);
        $this->render($update, $user, $screen['text'], $screen['kb']);
    }

    private function checkOrder(TelegramUpdate $update, User $user, int $orderId): void
    {
        $order = GatewayOrder::query()->find($orderId);
        $guard = AdminGuard::fromSettings($this->settings);

        $allowed = $order !== null
            && ((string) $order->chat_id === (string) $user->chat_id
                || $guard->isAdmin($update->fromUserId() ?? $user->chat_id));

        if (! $allowed) {
            $this->render($update, $user, '⛔ این سفارش متعلق به شما نیست.', []);

            return;
        }

        $result = $this->gateways->verifyAndFinalize($orderId);
        $text = $result['message'] !== '' ? $result['message'] : $this->gateways->statusText($order);

        if ($result['status'] !== 'PENDING') {
            $this->state->clear($user);
        }

        $kb = [];
        if ($result['status'] === 'PENDING' && $order) {
            $kb = [
                [['text' => '💳 | رفتن به صفحه پرداخت', 'url' => (string) $order->pay_url]],
                [['text' => '🔄 | بررسی دوباره', 'callback_data' => 'gw_chk:'.$orderId]],
                [['text' => '❌ | انصراف', 'callback_data' => 'gw_cancel:'.$orderId]],
            ];
        } else {
            $kb = [[['text' => '🏡 | خانه', 'callback_data' => 'main_menu']]];
        }

        $this->render($update, $user, $text, $kb);
    }

    private function cancelOrder(TelegramUpdate $update, User $user, int $orderId): void
    {
        $order = GatewayOrder::query()->find($orderId);

        if (! $order || (string) $order->chat_id !== (string) $user->chat_id) {
            $this->render($update, $user, '⛔ این سفارش متعلق به شما نیست.', []);

            return;
        }

        if ($order->status === 'PENDING') {
            $this->gateways->markFailed($orderId, 'CANCELED');
            $this->render($update, $user, '❌ سفارش لغو شد.', [['text' => '🏡 | خانه', 'callback_data' => 'main_menu']]);

            return;
        }

        $this->render($update, $user, $this->gateways->statusText($order), [['text' => '🏡 | خانه', 'callback_data' => 'main_menu']]);
    }

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
