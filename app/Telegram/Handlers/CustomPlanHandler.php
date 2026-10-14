<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\Payment;
use App\Models\User;
use App\Services\Panel\PanelSettingsService;
use App\Services\Shop\CustomPlanBotService;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Support\Facades\DB;

class CustomPlanHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly UserStateService $state,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();
        if ($data === null) {
            return false;
        }

        return str_starts_with($data, 'customplan_');
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = (string) $update->callbackData();
        $query = substr($data, strlen('customplan_'));

        match (true) {
            $query === 'buy' || str_starts_with($query, 'buy:') => $this->createOrder($update, $user, $query),
            $query === 'confirm' || str_starts_with($query, 'confirm:') => $this->confirm($update, $user),
            $query === 'cancel' || str_starts_with($query, 'cancel:') => $this->cancel($update, $user),
            default => null,
        };
    }

    private function argument(string $data): string
    {
        $parts = explode(':', $data, 2);

        return $parts[1] ?? '';
    }

    private function createOrder(TelegramUpdate $update, User $user, string $query): void
    {
        $planId = $this->argument($query);
        $state = $this->state->get($user) ?? [];

        $gb = (int) ($state['gb'] ?? 0);
        $days = (int) ($state['days'] ?? 0);

        if ($gb <= 0 || $days <= 0) {
            $this->telegram->sendMessage($user->chat_id, 'خطایی رخ داد، لطفاً از ابتدا شروع کنید.', [
                'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔙 | بازگشت', 'callback_data' => 'main_menu']],
                ]]),
            ]);

            return;
        }

        $priceResult = CustomPlanBotService::priceFor($gb, $days);
        if (! $priceResult['ok']) {
            $this->telegram->sendMessage($user->chat_id, $priceResult['error'], [
                'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔙 | بازگشت', 'callback_data' => 'main_menu']],
                ]]),
            ]);

            return;
        }

        $total = $priceResult['total'];
        $this->state->set($user, [
            'action' => 'customplan_pending',
            'chat_id' => $user->chat_id,
            'gb' => $gb,
            'days' => $days,
            'total' => $total,
            'plan_id' => $planId !== '' ? $planId : ($state['plan_id'] ?? null),
            'final_price' => $total,
        ]);

        $text = CustomPlanBotService::buildOrderText($gb, $days, $total);
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ | تایید', 'callback_data' => 'customplan_confirm'.($planId !== '' ? ':'.$planId : '')]],
                [['text' => '❌ | انصراف', 'callback_data' => 'customplan_cancel']],
            ],
        ];

        $this->render($update, $user, $text, $keyboard);
    }

    private function confirm(TelegramUpdate $update, User $user): void
    {
        $state = $this->state->get($user) ?? [];
        if (($state['action'] ?? null) !== 'customplan_pending') {
            $this->telegram->sendMessage($user->chat_id, 'خطایی رخ داد، لطفاً از ابتدا شروع کنید.', [
                'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔙 | بازگشت', 'callback_data' => 'main_menu']],
                ]]),
            ]);

            return;
        }

        if ((string) ($state['chat_id'] ?? '') !== (string) $user->chat_id) {
            $this->telegram->sendMessage($user->chat_id, 'خطایی رخ داد، لطفاً از ابتدا شروع کنید.', [
                'reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '🔙 | بازگشت', 'callback_data' => 'main_menu']],
                ]]),
            ]);

            return;
        }

        $gb = (int) ($state['gb'] ?? 0);
        $days = (int) ($state['days'] ?? 0);
        $total = (int) ($state['total'] ?? 0);
        $planId = $state['plan_id'] ?? null;

        $paymentId = DB::table('payments')->insertGetId([
            'chat_id' => $user->chat_id,
            'client_id' => Payment::NEW_CLIENT,
            'plan_id' => $planId ?? 'custom',
            'price' => number_format($total),
            'is_paid' => null,
            'method' => 'custom',
            'custom_gb' => $gb,
            'custom_days' => $days,
            'created_at' => now(),
        ]);

        $payment = Payment::query()->find($paymentId);

        $this->notifyAdmins($payment, $user, $gb, $days, $total);

        $this->state->clear($user);
    }

    private function cancel(TelegramUpdate $update, User $user): void
    {
        $this->state->clear($user);
        $this->telegram->sendMessage($user->chat_id, 'درخواست پلن سفارشی لغو شد.', [
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => '🔙 | بازگشت', 'callback_data' => 'main_menu']],
            ]]),
        ]);
    }

    private function render(TelegramUpdate $update, User $user, string $text, array $keyboard): void
    {
        $messageId = $update->callbackMessageId();
        if ($messageId !== null) {
            try {
                $this->telegram->editMessageText($user->chat_id, $messageId, $text, [
                    'reply_markup' => json_encode($keyboard),
                ]);

                return;
            } catch (\Throwable $e) {
                // fallback to send
            }
        }

        $this->telegram->sendMessage($user->chat_id, $text, [
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function notifyAdmins(Payment $payment, User $user, int $gb, int $days, int $total): void
    {
        $adminIds = (array) config('connectix_bot.admin_ids', []);
        $panelAdmins = [];

        try {
            if (method_exists(PanelSettingsService::class, 'adminChatIds')) {
                $panelAdmins = PanelSettingsService::adminChatIds();
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $allAdmins = array_unique(array_merge($adminIds, $panelAdmins));

        $text = 'درخواست پلن سفارشی جدید'.PHP_EOL.
            'کاربر: '.($user->telegram_id ?? $user->chat_id).PHP_EOL.
            'حجم: '.$gb.' گیگابایت'.PHP_EOL.
            'مدت: '.$days.' روز'.PHP_EOL.
            'مبلغ: '.number_format($total).' تومان';

        foreach ($allAdmins as $adminId) {
            try {
                $this->telegram->sendMessage($adminId, $text);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }
}
