<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\Services\Plan\PlanService;
use App\Services\Purchase\ClientProvisioner;
use App\Services\Purchase\PurchaseService;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Services\Wallet\WalletService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Support\Facades\DB;

/**
 * The new account purchase flow.
 *
 * Port of the `buy` and `group` menus plus the `group`, `count` and `plan`
 * branches of `buy()` and the checkout steps that a card payment and a wallet
 * payment share.
 *
 * The callbacks walk the user forward:
 *
 *   action:buy_or_renew_service → group → buy_group:<group>
 *       → buy_count:<n> → buy_plan:<id> → pay_card:<price> | pay_wallet:<price>
 *
 * The price inside a callback is only ever used to render a button. What gets
 * charged is always re-read from the panel, so editing a callback cannot change
 * what a user pays.
 */
class PurchaseHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly PurchaseService $purchase,
        private readonly PaymentService $payments,
        private readonly WalletService $wallets,
        private readonly PlanService $plans,
        private readonly MessageFactory $messages,
        private readonly UserStateService $state,
        private readonly ClientProvisioner $provisioner,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();

        if ($data === null) {
            return false;
        }

        return in_array($data, ['buy', 'action:buy_or_renew_service', 'group'], true)
            || str_starts_with($data, 'buy_group:')
            || str_starts_with($data, 'buy_count:')
            || str_starts_with($data, 'buy_plan:')
            || str_starts_with($data, 'pay_card:')
            || str_starts_with($data, 'pay_wallet:')
            || str_starts_with($data, 'discount_set:');
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = (string) $update->callbackData();

        match (true) {
            $data === 'buy', $data === 'action:buy_or_renew_service' => $this->showBuyMenu($update, $user),
            $data === 'group' => $this->showGroups($update, $user),
            str_starts_with($data, 'buy_group:') => $this->showDeviceCounts($update, $user, $this->argument($data)),
            str_starts_with($data, 'buy_count:') => $this->showPlans($update, $user, $this->argument($data)),
            str_starts_with($data, 'buy_plan:') => $this->showPaymentMethods($update, $user, $this->argument($data)),
            str_starts_with($data, 'pay_card:') => $this->beginCardPayment($update, $user, $this->argument($data)),
            str_starts_with($data, 'discount_set:') => $this->askForCoupon($update, $user, $this->argument($data)),
            str_starts_with($data, 'pay_wallet:') => $this->payWithWallet($update, $user, $this->argument($data)),
            default => null,
        };
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
     * The buy submenu.
     *
     * Port of `keyboard('buy')`: renewal only appears once the user owns an
     * account.
     */
    private function showBuyMenu(TelegramUpdate $update, User $user): void
    {
        $rows = [];

        if ($this->purchase->hasAccounts($user)) {
            $rows[] = [['text' => '🔄️ | تمدید اکانت فعلی', 'callback_data' => 'renew']];
        }

        $rows[] = [['text' => '🛍 | خرید اکانت جدید', 'callback_data' => 'group']];
        $rows[] = [['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']];

        $this->render($update, $user, $this->messages->make('buy'), $rows);
    }

    /**
     * The plan group list.
     *
     * Port of `keyboard('group')`.
     */
    private function showGroups(TelegramUpdate $update, User $user): void
    {
        $names = [];

        $rows = [];

        foreach ($this->purchase->groups() as $group) {
            $names[] = $group['name'];

            $rows[] = [[
                'text' => $group['label'],
                'callback_data' => 'buy_group:'.$group['name'],
            ]];
        }

        $rows[] = [
            ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
            ['text' => '↪️ | بازگشت', 'callback_data' => 'buy'],
        ];

        $this->render($update, $user, $this->messages->groupMenu($names), $rows);
    }

    /**
     * The device count keyboard for a group.
     *
     * Port of the `group` branch of `buy()`. Two counts share a row and the
     * pair is laid out right to left, which is what legacy produced with
     * `$row[] = $buttons[$i + 1]; $row[] = $buttons[$i];`.
     */
    private function showDeviceCounts(TelegramUpdate $update, User $user, string $group): void
    {
        $this->purchase->chooseGroup($user, $group);

        $counts = $this->purchase->deviceCountsFor($group);

        if ($counts === []) {
            $this->state->clear($user);

            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'هیچ پلن معتبری در این گروه وجود ندارد.',
            );

            return;
        }

        $buttons = [];

        foreach ($counts as $count) {
            $buttons[] = [
                'text' => $this->deviceEmoji($count)." | {$count} کاربر",
                'callback_data' => 'buy_count:'.$count,
            ];
        }

        $rows = [];

        for ($i = 0; $i < count($buttons); $i += 2) {
            $rows[] = isset($buttons[$i + 1])
                ? [$buttons[$i + 1], $buttons[$i]]
                : [$buttons[$i]];
        }

        $rows[] = [
            ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
            ['text' => '↪️ | بازگشت', 'callback_data' => 'group'],
        ];

        $text = $this->messages->make('count', [
            'groupName' => $this->plans->parseType($group),
        ]);

        $this->render($update, $user, $text, $rows);
    }

    /**
     * The keycap emoji legacy used for the small counts.
     */
    private function deviceEmoji(int $count): string
    {
        return match ($count) {
            1 => '1️⃣',
            2 => '2️⃣',
            3 => '3️⃣',
            4 => '4️⃣',
            default => (string) $count,
        };
    }

    /**
     * The plan list for a device count.
     *
     * Port of the `count` branch of `buy()`.
     */
    private function showPlans(TelegramUpdate $update, User $user, string $deviceCount): void
    {
        $group = (string) (($this->state->get($user) ?? [])['group'] ?? '');

        if ($group === '') {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'ابتدا یک گروه پلن انتخاب کنید.',
            );

            return;
        }

        $plans = $this->purchase->plansForDeviceCount($group, (int) $deviceCount);

        if ($plans === []) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'برای این تعداد کاربر، پلنی وجود ندارد.',
            );

            return;
        }

        $rows = [];

        foreach ($plans as $entry) {
            $rows[] = [[
                'text' => $entry['label'],
                'callback_data' => 'buy_plan:'.(string) $entry['plan']['id'],
            ]];
        }

        $rows[] = [
            ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
            ['text' => '↪️ | بازگشت', 'callback_data' => 'buy_group:'.$group],
        ];

        $label = $this->plans->parseType($group);

        $text = "فهرست و قیمت سرویس‌های {$deviceCount} کاربره {$label} به شرح لیست زیر است.\n\nلطفاً سرویس مدنظر خود را انتخاب کنید: 👇";

        $this->render($update, $user, $text, $rows);
    }

    /**
     * The payment method choice for a chosen plan.
     *
     * Port of the `plan` branch of `buy()`. The plan is looked up in the
     * sellable catalogue rather than in the group, so a plan that the panel
     * took off sale between two taps cannot be bought through an old button.
     */
    private function showPaymentMethods(TelegramUpdate $update, User $user, string $planId): void
    {
        $plan = $this->plans->findSellableById($planId);

        if ($plan === null) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                "پلن مورد نظر یافت نشد\nاین پلن دیگر موجود نمی باشد\n\nلطفا پلن دیگری را انتخاب کنید",
            );

            return;
        }

        $this->purchase->choosePlan($user, $plan);

        $state = $this->state->get($user) ?? [];

        $title = (string) ($this->plans->parsePlanTitle((string) ($plan['title'] ?? ''))['text'] ?? '');
        $price = (string) ($plan['sell_price'] ?? '');

        $account = (string) ($state['acc'] ?? UserStateService::ACC_NEW);
        $label = $account === UserStateService::ACC_NEW
            ? 'خرید اکانت جدید'
            : 'تمدید اکانت '.$account;

        $text = "📝 اطلاعات اکانت شما\n\n📧 {$label}\n📦 پلن: {$title}\n💰 مبلغ: {$price} تومان\n\nلطفا روش پرداخت را انتخاب کنید 👇🏻";

        $rows = [
            [['text' => '💳 | کارت به کارت', 'callback_data' => 'pay_card:'.$price]],
            [['text' => $this->purchase->walletLabel($user), 'callback_data' => 'pay_wallet:'.$price]],
            [
                ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                ['text' => '↪️ | بازگشت', 'callback_data' => 'buy_count:'.(string) ($plan['count_of_devices'] ?? '')],
            ],
        ];

        $this->render($update, $user, $text, $rows);
    }

    // -----------------------------------------------------------------
    // Checkout
    // -----------------------------------------------------------------

    /**
     * Start the card to card checkout and show the card details.
     *
     * Port of the `card` branch of `checkout()`. Nothing is charged and no
     * order exists yet: the order is written when the receipt arrives, which
     * is the payment flow's job.
     */
    private function beginCardPayment(TelegramUpdate $update, User $user, string $price): void
    {
        $this->purchase->beginCardPayment($user, PaymentMethod::Card->value);

        $text = $this->messages->make('card', ['amount' => $price]);

        $rows = [
            [['text' => '🎟 | وارد کردن کد تخفیف', 'callback_data' => 'discount_set:'.$price]],
            [['text' => '❌ | انصراف', 'callback_data' => 'main_menu']],
        ];

        $this->render($update, $user, $text, $rows);
    }

    /**
     * Ask for a coupon code.
     *
     * Port of the `set` branch of `discount()`. Cancelling returns to the card
     * screen with the same amount.
     */
    private function askForCoupon(TelegramUpdate $update, User $user, string $price): void
    {
        $state = $this->state->get($user) ?? [];

        $this->purchase->askForCoupon($user);

        $rows = [
            [['text' => '❌ | انصراف', 'callback_data' => 'pay_card:'.(string) ($state['price'] ?? $price)]],
        ];

        $this->render($update, $user, '🎟 کد تخفیف خود را وارد کنید:', $rows);
    }

    /**
     * Charge the wallet, then provision the account.
     *
     * Port of the `wallet` branch of `checkout()`. Legacy read the balance,
     * then wrote the order, the ledger entry and the balance change with no
     * transaction around them, so a failure between the steps left an order
     * nobody paid and two taps could both pass the balance check. Here the
     * debit, its ledger entry and the order are one transaction, the balance
     * check happens under the same row lock as the debit, and an order that is
     * already open for this buyer and plan is refused instead of doubled.
     */
    private function payWithWallet(TelegramUpdate $update, User $user, string $price): void
    {
        $state = $this->state->get($user) ?? [];

        $plan = $this->plans->findSellableById((string) ($state['plan'] ?? ''));

        if ($plan === null) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'پلن مورد نظر یافت نشد. لطفاً دوباره انتخاب کنید.',
            );

            return;
        }

        // A wallet order is charged the panel price: legacy offered the coupon
        // inside the card flow only, and stored the plan price for a wallet
        // payment even when a discounted figure was in state.
        $amount = (int) str_replace(',', '', (string) ($plan['sell_price'] ?? ''));

        if ($amount <= 0) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'مبلغ سفارش مشخص نیست. لطفاً دوباره پلن را انتخاب کنید.',
            );

            return;
        }

        $clientId = $this->purchase->resolveClientId($state);

        if ($clientId === null) {
            // Renewing an account that is neither linked nor known would
            // otherwise charge for a renewal and create a second account.
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                "⚠️ اکانت مورد نظر پیدا نشد.\nلطفاً از بخش «اکانت های من» آن را دوباره متصل کنید.",
            );

            return;
        }

        if ($this->hasOpenOrder($user, $clientId, (string) $plan['id'])) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                "⏳ یک سفارش باز برای این پلن وجود دارد.\nاگر پرداخت انجام شده، به پشتیبانی اطلاع دهید.",
            );

            return;
        }

        if (! $this->purchase->walletCanCover($user, $amount)) {
            $this->insufficientFunds($update, $user, $amount);

            return;
        }

        $payment = DB::transaction(function () use ($user, $clientId, $plan, $amount): ?Payment {
            $wallet = $this->wallets->decreaseIfAffordable($user->chat_id, $amount);

            if ($wallet === null) {
                // The balance changed between the pre-check and the lock, so
                // the pre-check was not authoritative after all.
                return null;
            }

            return $this->payments->create(
                chatId: $user->chat_id,
                clientId: $clientId,
                planId: (string) $plan['id'],
                price: (string) ($plan['sell_price'] ?? ''),
                method: PaymentMethod::Wallet,
                // The order is created undecided, exactly as legacy did, and
                // the debit is what makes it payable. Provisioning then
                // decides it.
                status: null,
            );
        });

        if ($payment === null) {
            $this->insufficientFunds($update, $user, $amount);

            return;
        }

        // Legacy deleted the menu message before provisioning, so the chat is
        // not left showing a payment button for an order that is already paid.
        $this->deleteStepMessage($update, $user);

        $this->state->clear($user);

        $this->provisioner->provision($payment);
    }

    /**
     * The alert legacy showed when the balance did not cover the order.
     */
    private function insufficientFunds(TelegramUpdate $update, User $user, int $amount): void
    {
        $balance = number_format($this->wallets->balance($user->chat_id));

        $this->telegram->answerCallbackQueryQuietly(
            $update->callbackId(),
            implode("\n", [
                '❌ موجودی کیف پول شما کافی نیست!',
                '',
                "💰 موجودی کیف پول شما: {$balance} تومان",
                '📦 قیمت پلن: '.number_format($amount).' تومان',
            ]),
        );
    }

    /**
     * Whether an undecided order for this buyer, account and plan is waiting.
     *
     * Legacy had no such guard, so a second tap on the wallet button created a
     * second order and took the money twice. Refusing the duplicate keeps the
     * buyer from being charged twice for one decision.
     */
    private function hasOpenOrder(User $user, string $clientId, string $planId): bool
    {
        return $this->payments
            ->forChat($user->chat_id)
            ->contains(fn (Payment $payment): bool => $payment->plan_id === $planId
                && (string) $payment->client_id === $clientId
                && $payment->isPending());
    }

    /**
     * Remove the step message, if this callback came from one.
     */
    private function deleteStepMessage(TelegramUpdate $update, User $user): void
    {
        $messageId = $update->callbackMessageId();

        if ($messageId === null) {
            return;
        }

        try {
            $this->telegram->deleteMessage($user->chat_id, $messageId);
        } catch (\Throwable) {
            // The message may already be gone, which is not worth a failure.
        }
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

    /**
     * Send or edit the current step's message.
     *
     * Editing keeps the chat from filling up with a copy of every step, which
     * is what legacy did for these menus.
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
