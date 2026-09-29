<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\Client;
use App\Models\User;
use App\Services\Connectix\ConnectixService;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Linking an account that already exists in the panel.
 *
 * Port of `addAccount()` and the `add_account` branch of bot.php.
 *
 * The flow asks for the username, then the password, checks both against the
 * panel, and only then writes the chat id onto the local row and tells the
 * panel about the same linkage.
 */
class AddAccountHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly ConnectixService $connectix,
        private readonly MessageFactory $messages,
        private readonly UserStateService $state,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        if ($update->callbackData() === 'add_account') {
            return true;
        }

        if ($update->text() === null) {
            return false;
        }

        return ($this->state->get($user) ?? [])['action'] ?? null === UserStateService::ACTION_ADD_ACCOUNT;
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = $update->callbackData();

        if ($data === 'add_account') {
            $this->askForUsername($update, $user);

            return;
        }

        $state = $this->state->get($user) ?? [];

        $step = $state['step'] ?? null;

        if ($step === 'get_username') {
            $this->askForPassword($user, (string) $update->text());

            return;
        }

        if ($step === 'get_password') {
            $this->link($update, $user, (string) ($state['username'] ?? ''), (string) $update->text());
        }
    }

    /**
     * Ask for the panel username.
     */
    private function askForUsername(TelegramUpdate $update, User $user): void
    {
        $this->state->startAddAccount($user);

        $this->render($update, $user, $this->messages->make('add_account'), [
            [['text' => '❌ | انصراف', 'callback_data' => 'accounts']],
        ]);
    }

    /**
     * Remember the username and ask for the password.
     *
     * Port of the `get_username` branch of bot.php. The cancel button kept its
     * legacy misspelling of `accounts`, which is what that menu answers to.
     */
    private function askForPassword(User $user, string $username): void
    {
        // `startAddAccount()` always reopens the username step, so the second
        // step is written directly, matching legacy's `get_password` state.
        $this->state->set($user, [
            'action' => UserStateService::ACTION_ADD_ACCOUNT,
            'step' => 'get_password',
            'username' => $username,
        ]);

        $this->telegram->sendMessage($user->chat_id, "نام کاربری واردشده: {$username}\nلطفا رمزعبور حساب را وارد نمایید", [
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '❌ | انصراف', 'callback_data' => 'acounts']],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Check the credentials and link the account to this chat.
     *
     * Port of the `add_account` branch of `addAccount()`.
     */
    private function link(TelegramUpdate $update, User $user, string $username, string $password): void
    {
        if ($username === '') {
            $this->state->startAddAccount($user);

            $this->render($update, $user, $this->messages->make('add_account'), [
                [['text' => '❌ | انصراف', 'callback_data' => 'accounts']],
            ]);

            return;
        }

        $client = $this->connectix->getClientByUsername($username);

        if ($client === null || ($client['username'] ?? '') === '' || ($client['password'] ?? null) !== $password) {
            $this->state->tryClear($user);

            $this->telegram->sendMessage($user->chat_id, 'نام کاربری و یا رمز عبور اشتباه است.', [
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '↪️ | بازگشت', 'callback_data' => 'accounts']],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return;
        }

        $clientId = (string) $client['id'];

        $devices = $this->deviceCountFor((string) ($client['plan_name'] ?? ''));

        DB::transaction(function () use ($client, $clientId, $user, $password, $devices): void {
            $local = Client::query()->find($clientId);

            if ($local === null) {
                // Legacy only ran the UPDATE, which silently did nothing when
                // the account was not in the local table. Inserting it here
                // keeps the button from appearing to work while the account
                // stays invisible to the renewal flow.
                Client::query()->create([
                    'id' => $clientId,
                    'count_of_devices' => $devices,
                    'username' => (string) ($client['username'] ?? ''),
                    'password' => $password,
                    'chat_id' => (string) $user->chat_id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                ]);

                return;
            }

            // Legacy only moved the ownership columns. The panel owns the
            // credentials and the plan, so re-writing them here would reset
            // `created_at` and could overwrite newer panel values with a
            // snapshot taken a moment earlier.
            $local->forceFill([
                'chat_id' => (string) $user->chat_id,
                'user_id' => $user->id,
            ])->save();
        });

        $this->state->clear($user);

        // The panel is told afterwards: if it refuses, the local row is not
        // silently correct and the next read tells the truth.
        try {
            $this->connectix->updateClientCredentials(
                clientId: $clientId,
                password: $password,
                countOfDevices: $devices,
                chatId: $user->chat_id,
                telegramUsername: $user->telegram_id,
            );
        } catch (\Throwable $e) {
            Log::error('The account was linked locally but the panel refused the update.', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->telegram->sendMessage(
            $user->chat_id,
            "✅ اکانت با نام کاربری {$username} با موفقیت حساب تلگرام شما متصل شد.",
            [
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '↪️ | بازگشت', 'callback_data' => 'accounts']],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
        );
    }

    /**
     * The device count the plan title carries, defaulting to one.
     *
     * Legacy read it with `preg_match('/\((\d+)x\)/')` and used the captured
     * number, or one when the title did not carry one.
     */
    private function deviceCountFor(string $planName): int
    {
        if (preg_match('/\((\d+)x\)/', $planName, $matches) === 1) {
            return (int) $matches[1];
        }

        return 1;
    }

    /**
     * Send or edit the prompt.
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
                'reply_markup' => $keyboard,
            ]);

            return;
        }

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => $keyboard,
        ]);
    }
}
