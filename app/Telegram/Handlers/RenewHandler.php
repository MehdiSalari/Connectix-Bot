<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Connectix\ConnectixService;
use App\Services\Plan\PlanService;
use App\Services\Purchase\PurchaseService;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

/**
 * The renewal flow.
 *
 * Port of `keyboard('renew')` and `renew()`.
 *
 * A renewal differs from a purchase in exactly one thing: the account is
 * already chosen when the plan is, so the flow reuses the same payment
 * callbacks and only the back buttons point somewhere else. The account is
 * kept in state under `acc`, and the shared checkout reads it from there.
 */
class RenewHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly PurchaseService $purchase,
        private readonly PlanService $plans,
        private readonly ConnectixService $connectix,
        private readonly MessageFactory $messages,
        private readonly UserStateService $state,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();

        if ($data === null) {
            return false;
        }

        return $data === 'renew'
            || str_starts_with($data, 'renew_acc:')
            || str_starts_with($data, 'renew_plan:');
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = (string) $update->callbackData();

        if ($data === 'renew') {
            $this->showAccounts($update, $user);

            return;
        }

        if (str_starts_with($data, 'renew_acc:')) {
            $this->showCurrentPlan($update, $user, $this->argument($data));

            return;
        }

        $this->showPaymentMethods($update, $user, $this->argument($data));
    }

    /**
     * The part of a callback after its first colon.
     */
    private function argument(string $data): string
    {
        return explode(':', $data, 2)[1] ?? '';
    }

    // -----------------------------------------------------------------
    // Menus
    // -----------------------------------------------------------------

    /**
     * The account picker.
     *
     * Port of `keyboard('renew')`. Newest account first, because legacy
     * reversed the rows it read. Each account shows the active plan, falling
     * back to the first queued one, exactly as legacy decided between them.
     */
    private function showAccounts(TelegramUpdate $update, User $user): void
    {
        $rows = [];

        foreach ($this->purchase->accountsNewestFirst($user) as $client) {
            [$label, $status] = $this->describe($client->id);

            $rows[] = [
                ['text' => $label, 'callback_data' => 'renew_acc:'.$client->username],
                ['text' => $status.' | '.$client->username, 'callback_data' => 'renew_acc:'.$client->username],
            ];
        }

        if ($rows === []) {
            $rows[] = [['text' => '🤷🏻 | اکانتی به تلگرام شما متصل نیست', 'callback_data' => 'not']];
        }

        $rows[] = [
            ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
            ['text' => '↪️ | بازگشت', 'callback_data' => 'buy'],
        ];

        $this->render($update, $user, $this->messages->make('renew'), $rows);
    }

    /**
     * The last plan bought for an account, and the choice to renew it.
     *
     * Port of the `acc` branch of `renew()`.
     */
    private function showCurrentPlan(TelegramUpdate $update, User $user, string $username): void
    {
        $client = $this->purchase->accountByUsername($username);

        if ($client === null || (string) $client->chat_id !== (string) $user->chat_id) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'این اکانت به حساب تلگرام شما متصل نیست.',
            );

            return;
        }

        $this->state->startRenewal($user, $username);

        $data = $this->connectix->getClientData((string) $client->id);

        if ($data === null) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'اطلاعات اکانت از پنل دریافت نشد. لطفاً کمی بعد دوباره تلاش کنید.',
            );

            return;
        }

        $plan = $this->currentPlan($data);

        if ($plan === null) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'برای این اکانت هیچ اشتراکی یافت نشد.',
            );

            return;
        }

        $title = (string) ($this->plans->parsePlanTitle((string) $plan['name'])['text'] ?? '');

        $text = "آخرین اشتراک خریداری شده برای اکانت {$username} به شرح زیر می باشد:\n\n📦 پلن: {$title}\n\nتمدید با همین پلن انجام شود یا درخواست پلن دیگری دارید؟";

        $rows = [
            [['text' => '🔃 | تمدید با همین پلن', 'callback_data' => 'renew_plan:'.$plan['name']]],
            [['text' => '➕ | انتخاب پلن دیگر', 'callback_data' => 'group']],
            [
                ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                ['text' => '↪️ | بازگشت', 'callback_data' => 'renew'],
            ],
        ];

        $this->render($update, $user, $text, $rows);
    }

    /**
     * The payment methods for a plan chosen during a renewal.
     *
     * Port of the `plan` branch of `renew()`. The plan is matched by its exact
     * title, which is what the account's own plan name carried, and the account
     * is kept so the shared checkout renews instead of creating a new one.
     */
    private function showPaymentMethods(TelegramUpdate $update, User $user, string $planTitle): void
    {
        $plan = $this->plans->findByTitle($planTitle);

        if ($plan === null) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                "پلن مورد نظر یافت نشد\nاین پلن دیگر موجود نمی باشد\n\nلطفا پلن دیگری را انتخاب کنید",
            );

            return;
        }

        $state = $this->state->get($user) ?? [];
        $account = (string) ($state['acc'] ?? '');

        if ($account === '' || $account === UserStateService::ACC_NEW) {
            // Reaching the payment step without an account means the renewal
            // lost its subject, so the user is sent back to the picker rather
            // than being charged for a new account they did not ask for.
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'ابتدا اکانت مورد نظر برای تمدید را انتخاب کنید.',
            );

            return;
        }

        $this->state->set($user, [
            'action' => UserStateService::ACTION_RENEW,
            'step' => 'plan',
            'acc' => $account,
            'group' => $state['group'] ?? null,
            'plan' => (string) $plan['id'],
            'price' => (string) ($plan['sell_price'] ?? ''),
            'pay' => null,
        ]);

        $title = (string) ($this->plans->parsePlanTitle((string) ($plan['title'] ?? ''))['text'] ?? '');
        $price = (string) ($plan['sell_price'] ?? '');

        $text = "📝 اطلاعات اکانت شما\n\n📧 تمدید اکانت: {$account}\n📦 پلن: {$title}\n💰 مبلغ: {$price} تومان\n\nلطفا روش پرداخت را انتخاب کنید 👇🏻";

        $rows = [
            [['text' => '💳 | کارت به کارت', 'callback_data' => 'pay_card:'.$price]],
            [['text' => $this->purchase->walletLabel($user), 'callback_data' => 'pay_wallet:'.$price]],
            [
                ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                ['text' => '↪️ | بازگشت', 'callback_data' => 'renew_acc:'.$account],
            ],
        ];

        $this->render($update, $user, $text, $rows);
    }

    // -----------------------------------------------------------------
    // Accounts
    // -----------------------------------------------------------------

    /**
     * The label and status legacy showed for an account in the picker.
     *
     * Port of the plan selection inside `keyboard('renew')` and
     * `keyboard('accounts')`: the first active plan wins, otherwise the first
     * queued one, otherwise the account reads as having no subscription.
     *
     * @return array{0: string, 1: string}
     */
    private function describe(string $clientId): array
    {
        $data = $this->connectix->getClientData($clientId);

        if ($data === null) {
            return ['بدون اشتراک', '🔴 غیرفعال'];
        }

        $plan = $this->currentPlan($data);

        if ($plan === null) {
            return ['بدون اشتراک', '🔴 غیرفعال'];
        }

        $title = (string) ($this->plans->parsePlanTitle((string) $plan['name'], true)['text'] ?? '');

        $isActive = ($plan['is_active'] ?? false) == true;

        return [$title, $isActive ? '🟢 فعال' : '🔵 در صف'];
    }

    /**
     * The plan an account is currently on: the first active one, else the
     * first queued one.
     *
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>|null
     */
    private function currentPlan(array $client): ?array
    {
        $plans = $client['plans'] ?? [];

        if (! is_array($plans)) {
            return null;
        }

        $queued = null;

        foreach ($plans as $plan) {
            if (! is_array($plan)) {
                continue;
            }

            if (($plan['is_active'] ?? false) == true) {
                return $plan;
            }

            if ($queued === null && ($plan['is_in_queue'] ?? false)) {
                $queued = $plan;
            }
        }

        return $queued;
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

    /**
     * Send or edit the current step's message.
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
