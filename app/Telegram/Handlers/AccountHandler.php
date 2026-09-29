<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\Client;
use App\Models\User;
use App\Services\Connectix\ConnectixService;
use App\Services\Plan\PlanService;
use App\Services\Purchase\PurchaseService;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

/**
 * The "my accounts" menu and the detail page behind each row.
 *
 * Port of the `accounts` branch of bot.php, `keyboard('accounts')` and
 * `showClient()`: the list names every linked account with its current plan
 * and status, and each row opens a full detail page with the account
 * credentials, the active subscription, the queued ones and a one-click way to
 * renew or buy for exactly that account.
 *
 * Legacy never checked that the requested client id belonged to the caller;
 * the detail page does, because a forged `showClient_` callback would
 * otherwise hand out another user's credentials.
 */
class AccountHandler implements UpdateHandler
{
    private const MENU = 'accounts';

    private const DETAIL_PREFIX = 'showClient_';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly PurchaseService $purchase,
        private readonly ConnectixService $connectix,
        private readonly PlanService $plans,
        private readonly MessageFactory $messages,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();

        if ($data === null) {
            return false;
        }

        return $data === self::MENU || str_starts_with($data, self::DETAIL_PREFIX);
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = (string) $update->callbackData();

        if ($data === self::MENU) {
            $this->showList($update, $user);

            return;
        }

        $this->showDetail($update, $user, substr($data, strlen(self::DETAIL_PREFIX)));
    }

    // -----------------------------------------------------------------
    // Menu
    // -----------------------------------------------------------------

    /**
     * The list of linked accounts, port of `keyboard('accounts')`.
     *
     * Newest account first, two buttons per account both opening its detail
     * page, and the same empty state legacy drew: nothing to show, a shortcut
     * to buy, and the add-account row either way.
     */
    private function showList(TelegramUpdate $update, User $user): void
    {
        $rows = [];

        foreach ($this->purchase->accountsNewestFirst($user) as $client) {
            [$label, $status] = $this->purchase->describeAccount((string) $client->id);

            $rows[] = [
                ['text' => $label, 'callback_data' => self::DETAIL_PREFIX.$client->id],
                ['text' => $status.' | '.$client->username, 'callback_data' => self::DETAIL_PREFIX.$client->id],
            ];
        }

        if ($rows === []) {
            $rows[] = [['text' => '🤷🏻 | اکانتی به تلگرام شما متصل نیست', 'callback_data' => 'not']];
            $rows[] = [['text' => '🛍 | خرید اکانت جدید', 'callback_data' => 'group']];
        }

        $rows[] = [
            ['text' => '➕ | افزودن اکانت به لیست', 'callback_data' => 'add_account'],
            ['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu'],
        ];

        $this->render($update, $user, $this->messages->make('accounts'), $rows);
    }

    // -----------------------------------------------------------------
    // Detail
    // -----------------------------------------------------------------

    /**
     * The full page for one account, port of `showClient()`.
     *
     * The panel is asked for the client, the subscription sections are split
     * exactly the way legacy split them (an in-queue plan counts as queued
     * even when it is also active), and the primary button renews or buys for
     * this account depending on whether a subscription is running.
     */
    private function showDetail(TelegramUpdate $update, User $user, string $clientId): void
    {
        $client = Client::query()->find($clientId);

        if ($client === null || (string) $client->chat_id !== (string) $user->chat_id) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'این اکانت به حساب تلگرام شما متصل نیست.',
            );

            return;
        }

        $data = $this->connectix->getClientData($clientId);

        if ($data === null) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'اطلاعات اکانت از پنل دریافت نشد. لطفاً کمی بعد دوباره تلاش کنید.',
            );

            return;
        }

        $activePlan = null;
        $queuedPlans = [];

        foreach (($data['plans'] ?? []) as $plan) {
            if (! is_array($plan)) {
                continue;
            }

            if ($plan['is_in_queue'] ?? false) {
                $queuedPlans[] = $plan;
            } elseif (($plan['is_active'] ?? false) == true) {
                $activePlan = $plan;
            }
        }

        $username = (string) ($data['username'] ?? '');
        $action = $activePlan
            ? ['text' => '📆 | رزرو اشتراک جدید برای این اکانت', 'callback_data' => 'renew_acc:'.$username.':accounts']
            : ['text' => '🛒 | خرید اشتراک برای این اکانت', 'callback_data' => 'renew_acc:'.$username.':accounts'];

        $rows = [
            [$action],
            [
                ['text' => '🏡 | خانه', 'callback_data' => 'main_menu'],
                ['text' => '↪️ | بازگشت', 'callback_data' => self::MENU],
            ],
        ];

        $this->render($update, $user, $this->detailText($data, $activePlan, $queuedPlans), $rows);
    }

    /**
     * The HTML body of the detail page, port of the message `showClient()`
     * assembled.
     *
     * @param  array<string, mixed>  $client
     * @param  array<string, mixed>|null  $activePlan
     * @param  array<int, array<string, mixed>>  $queuedPlans
     */
    private function detailText(array $client, ?array $activePlan, array $queuedPlans): string
    {
        $message = "📝 اطلاعات اکانت شما\n\n";

        $message .= '👤 نام: <b>'.((string) ($client['name'] ?? ''))."</b>\n";

        if (($client['username'] ?? '') !== '') {
            $message .= '📧 یوزرنیم: <code>'.((string) $client['username'])."</code>\n";
        }

        if (($client['password'] ?? '') !== '') {
            $message .= '🔑 پسورد: <code>'.((string) $client['password'])."</code>\n";
        }

        $message .= '📱 تعداد دستگاه مجاز: <b>'.((string) ($client['count_of_devices'] ?? ''))."</b>\n\n";

        if (! empty($client['subscription_link'])) {
            $message .= '🔗 لینک سابسکریشن: <code>'.((string) $client['subscription_link'])."</code>\n";
        }

        if ($activePlan !== null) {
            $message .= "\n🎯 <b>اشتراک فعال فعلی</b>\n";
            $message .= '📦 پلن: '.$this->planTitle($activePlan)."\n";
            $message .= '⏳ انقضا: <b>'.((string) ($activePlan['expire_date'] ?? ''))."</b>\n";
            $message .= '📊 مصرف ترافیک: '.((string) ($activePlan['total_used_traffic'] ?? ''))."\n";
            $message .= '🗓 فعال شده در: '.((string) ($activePlan['activated_at'] ?? ''))."\n";
        } else {
            $message .= "\n⚠️ در حال حاضر هیچ اشتراک فعالی وجود ندارد.\n";
        }

        if ($queuedPlans !== []) {
            $message .= "\n\n⏳ <b>اشتراک‌های رزرو شده (در صف فعال‌سازی)</b>\n";

            foreach (array_reverse($queuedPlans) as $i => $plan) {
                $message .= "\n".($i + 1).'. پلن: '.$this->planTitle($plan)."\n";
                $message .= '   انقضا: '.((string) ($plan['expire_date'] ?? ''))."\n";
                $message .= '   تاریخ خرید: '.((string) ($plan['created_at'] ?? ''))."\n";

                if (($plan['gift_days'] ?? 0) != 0) {
                    $message .= '   +'.((int) $plan['gift_days'])." روز هدیه\n";
                }
            }
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function planTitle(array $plan): string
    {
        $parsed = $this->plans->parsePlanTitle((string) ($plan['name'] ?? ''));

        return (string) ($parsed['text'] ?? '');
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

    /**
     * Edit the callback message when there is one, otherwise send fresh.
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
