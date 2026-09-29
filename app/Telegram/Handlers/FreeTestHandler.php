<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Exceptions\ConnectixApiException;
use App\Models\Client;
use App\Models\User;
use App\Services\Connectix\ConnectixService;
use App\Services\Plan\PlanService;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Support\Facades\Log;

/**
 * The free trial account.
 *
 * Port of the `get_test` and `getTest_*` branches of bot.php plus the legacy
 * `getTest()` helper: the menu lists the free plans, and choosing one creates
 * a client on the panel, marks the user's trial as used and stores the client
 * locally.
 */
class FreeTestHandler implements UpdateHandler
{
    private const TRIAL_MENU = 'get_test';

    private const TRIAL_PREFIX = 'getTest_';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly PlanService $plans,
        private readonly ConnectixService $connectix,
        private readonly MessageFactory $messages,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();

        return $data !== null && ($data === self::TRIAL_MENU || str_starts_with($data, self::TRIAL_PREFIX));
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = (string) $update->callbackData();

        if ($data === self::TRIAL_MENU) {
            $this->showTrialMenu($update, $user);

            return;
        }

        $type = substr($data, strlen(self::TRIAL_PREFIX));

        $this->createTrialAccount($update, $user, $type);
    }

    // -----------------------------------------------------------------
    // Trial menu
    // -----------------------------------------------------------------

    /**
     * The list of free plans, mirrored from `keyboard('get_test')`.
     *
     * Legacy rendered one button per free plan. Plans of the same group carry
     * the same label and answer the same callback, so the duplicates are
     * dropped here; the visible menu is unchanged.
     */
    private function showTrialMenu(TelegramUpdate $update, User $user): void
    {
        $freePlans = $this->plans->freeTrialPlans();

        if ($freePlans === []) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                '❌ اکانت تست غیر فعال است!'
            );

            return;
        }

        $rows = [];

        foreach ($freePlans as $plan) {
            $group = $this->groupOf($plan);

            if ($group === '' || $this->alreadyListed($group, $rows)) {
                continue;
            }

            $rows[] = [[
                'text' => $this->plans->parseTypeWithEmoji($group),
                'callback_data' => self::TRIAL_PREFIX.$group,
            ]];
        }

        $rows[] = [['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']];

        $this->render($update, $user, $this->messages->make('get_test'), $rows);
    }

    /**
     * Whether a group already has a button in the menu rows.
     *
     * @param  array<int, array<int, array<string, string>>>  $rows
     */
    private function alreadyListed(string $group, array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row[0]['callback_data'] ?? null) === self::TRIAL_PREFIX.$group) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------
    // Trial account
    // -----------------------------------------------------------------

    /**
     * Provision the trial for the requested plan type.
     *
     * Port of `getTest()`. The order of the checks matters: the free plan list
     * and the plan selection come first, then the used-trial guard, then the
     * panel round trips and finally the local database writes.
     */
    private function createTrialAccount(TelegramUpdate $update, User $user, string $type): void
    {
        $freePlans = $this->plans->freeTrialPlans();

        if ($freePlans === []) {
            $this->render($update, $user, 'خطا در دریافت لیست پلن‌ها از سرور', []);

            return;
        }

        $selected = $this->selectFreePlan($freePlans, $type);

        if ($selected === null) {
            $this->render($update, $user, "پلن مناسب برای نوع درخواستی ({$type}) یافت نشد.", []);

            return;
        }

        if ($user->hasUsedTest()) {
            $this->render(
                $update,
                $user,
                '⚠️ شما قبلا درخواست تست داده اید!',
                [[['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']]],
            );

            return;
        }

        $clientId = $this->createPanelClient($update, $user, $selected);

        if ($clientId === null) {
            return;
        }

        // The panel client exists now, so the trial is consumed from here on.
        // Legacy committed `test = 1` for every run that reached this point
        // (autocommit made the flag survive even a failed local insert), and
        // a retry after a later failure would provision a second free client.
        if (! $this->markTrialUsed($update, $user)) {
            return;
        }

        $client = $this->fetchPanelClient($update, $user, $clientId);

        if ($client === null) {
            return;
        }

        if (! $this->storeLocally($update, $user, $clientId, $client)) {
            return;
        }

        $this->confirm($update, $user, $client);
    }

    /**
     * Pick the free plan the type asks for, port of the two branches of
     * `getTest()`: `economic` wants a title with "+ Economic", anything else
     * the first plan that stays clear of "Economic" and "Sublink".
     *
     * @param  array<int, array<string, mixed>>  $plans
     * @return array<string, mixed>|null
     */
    private function selectFreePlan(array $plans, string $type): ?array
    {
        foreach ($plans as $plan) {
            if (($plan['type'] ?? null) !== 'Free') {
                continue;
            }

            $title = (string) ($plan['title'] ?? '');

            if ($type === 'economic') {
                if (strpos($title, '+ Economic') !== false || strpos($title, '+Economic') !== false) {
                    return $plan;
                }

                continue;
            }

            if ($type === 'default' && strpos($title, 'Economic') === false && strpos($title, 'Sublink') === false) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Create the client on the panel and return its id, or null on failure.
     *
     * Port of the `createClient()` call and the two error strings beside it.
     */
    private function createPanelClient(TelegramUpdate $update, User $user, array $plan): ?string
    {
        try {
            $created = $this->connectix->createClient(
                $user->name,
                $user->chat_id,
                $user->telegram_id,
                (string) ($plan['id'] ?? ''),
            );
        } catch (ConnectixApiException $e) {
            Log::error('Free test client could not be created on the panel.', [
                'chat_id' => $user->chat_id,
                'error' => $e->getMessage(),
            ]);

            $this->render($update, $user, 'خطا در ایجاد اکانت روی سرور', []);

            return null;
        }

        $clientId = $created['client_id'] ?? null;

        if (! is_string($clientId) || $clientId === '') {
            $this->render($update, $user, 'خطا در ایجاد اکانت', []);

            return null;
        }

        return $clientId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPanelClient(TelegramUpdate $update, User $user, string $clientId): ?array
    {
        try {
            $client = $this->connectix->getClientData($clientId);
        } catch (ConnectixApiException $e) {
            Log::error('Free test client could not be read from the panel.', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            $client = null;
        }

        if ($client === null) {
            $this->render($update, $user, 'خطا در دریافت اطلاعات اکانت', []);

            return null;
        }

        return $client;
    }

    /**
     * Mark the user's trial as used, the `UPDATE users SET test = 1` of the
     * legacy database block.
     *
     * Kept separate from the client insert below and committed on its own,
     * exactly as legacy autocommit did: a trial whose local insert fails must
     * still be spent, otherwise a retry provisions a second free client.
     */
    private function markTrialUsed(TelegramUpdate $update, User $user): bool
    {
        try {
            $user->forceFill(['test' => true])->save();
        } catch (\Throwable $e) {
            Log::error('Free test flag could not be saved.', [
                'chat_id' => $user->chat_id,
                'error' => $e->getMessage(),
            ]);

            $this->render($update, $user, 'خطا در ذخیره اطلاعات اکانت', []);

            return false;
        }

        return true;
    }

    /**
     * Keep a local copy of the account, the `INSERT INTO clients` of the
     * legacy database block.
     *
     * Returns false when the row could not be written. Legacy stopped here
     * with the same error and never sent the credentials, so the caller must
     * not fall through to the success message either.
     *
     * @param  array<string, mixed>  $client
     */
    private function storeLocally(TelegramUpdate $update, User $user, string $clientId, array $client): bool
    {
        try {
            Client::query()->create([
                'id' => $clientId,
                'count_of_devices' => (int) ($client['count_of_devices'] ?? 0),
                'username' => (string) ($client['username'] ?? ''),
                'password' => (string) ($client['password'] ?? ''),
                'chat_id' => (string) $user->chat_id,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Free test client could not be stored locally.', [
                'client_id' => $clientId,
                'chat_id' => $user->chat_id,
                'error' => $e->getMessage(),
            ]);

            $this->render($update, $user, 'خطا در ذخیره اطلاعات اکانت', []);

            return false;
        }

        return true;
    }

    /**
     * The account message with the credentials, port of the tail of `getTest()`.
     *
     * @param  array<string, mixed>  $client
     */
    private function confirm(TelegramUpdate $update, User $user, array $client): void
    {
        $username = (string) ($client['username'] ?? '');
        $password = (string) ($client['password'] ?? '');
        $sublink = (string) ($client['subscription_link'] ?? '');

        $body = "\n\n👤 نام کاربری: <code>{$username}</code>\n🔑 رمز عبور: <code>{$password}</code>\n";

        if ($sublink !== '') {
            $body .= "\n🔗 لینک سابسکریبشن: <code>{$sublink}</code>";
        }

        $rows = [
            [['text' => '📦 | اکانت های من', 'callback_data' => 'accounts']],
            [['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']],
        ];

        $this->render($update, $user, $this->messages->make('test_created').$body, $rows);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $plan
     */
    private function groupOf(array $plan): string
    {
        return trim((string) ($plan['group_name_translations']['en'] ?? $plan['group_name'] ?? ''));
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
