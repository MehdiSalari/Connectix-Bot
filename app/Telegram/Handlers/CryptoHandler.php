<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\CryptoPayment;
use App\Models\User;
use App\Services\Crypto\CryptoPayService;
use App\Services\Panel\PanelSettingsService;
use App\Services\Telegram\AdminGuard;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

/**
 * The customer-facing crypto payment flow, plus the admin decision buttons.
 *
 * Port of `CryptoPayService::route()` from the feature fork and the
 * `crypto_hash` text step of its `handleUserInput()`:
 *
 *  - `crypto_menu:<mode>`   the network chooser (buy / wallet entry),
 *  - `crypto_net:<mode>:<net>` create the order, arm `crypto_hash` state,
 *  - `crypto_chk:<id>`      re-check a transaction against the chain,
 *  - `crypto_ref:<id>`      re-price an order with no hash yet,
 *  - `crypto_cancel:<id>`   cancel it,
 *  - `crypto_ok:<id>` / `crypto_no:<id>`  admin confirm / reject, answered
 *    with the fork's 403 text for anyone who is not an administrator;
 *  - any message (or photo) while the user state says `crypto_hash` is
 *    treated as the transaction hash the order waits for.
 *
 * The state follows the PaymentHandler pattern: read with
 * {@see UserStateService::get()}, written only through the service, and
 * cleared as soon as the order leaves the waiting status.
 */
class CryptoHandler implements UpdateHandler
{
    /** The fork's answer to a non-admin pressing a decision button. */
    private const FORBIDDEN = '⛔ دسترسی غیرمجاز.';

    private const CALLBACK_PREFIX = 'crypto_';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly UserStateService $state,
        private readonly CryptoPayService $crypto,
        private readonly PanelSettingsService $settings,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();

        if ($data !== null) {
            return str_starts_with($data, self::CALLBACK_PREFIX);
        }

        if ($update->text() === null && $update->photo() === []) {
            return false;
        }

        $action = ($this->state->get($user) ?? [])['action'] ?? null;

        return $action === CryptoPayService::ACTION_HASH;
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = $update->callbackData();

        if ($data !== null && str_starts_with($data, self::CALLBACK_PREFIX)) {
            $this->handleCallback($update, $user, substr($data, strlen(self::CALLBACK_PREFIX)));

            return;
        }

        $this->handleHashInput($update, $user);
    }

    // -----------------------------------------------------------------
    // Callbacks
    // -----------------------------------------------------------------

    /**
     * `menu:<mode>` | `net:<mode>:<net>` | `chk:<id>` | `ref:<id>` |
     * `cancel:<id>` | `ok:<id>` | `no:<id>`.
     */
    private function handleCallback(TelegramUpdate $update, User $user, string $query): void
    {
        $parts = explode(':', $query);
        $action = $parts[0] ?? '';

        switch ($action) {
            case 'menu':
                $screen = $this->crypto->menuScreen($parts[1] ?? 'buy');
                $this->render($update, $user, $screen['text'], $screen['kb']);

                return;

            case 'net':
                $this->createOrder($update, $user, $parts[1] ?? 'buy', $parts[2] ?? '');

                return;

            case 'chk':
                $this->recheck($update, $user, (int) ($parts[1] ?? 0));

                return;

            case 'ref':
                $screen = $this->crypto->refreshOrder((int) ($parts[1] ?? 0), (string) $user->chat_id);
                $this->render($update, $user, $screen['text'], $screen['kb']);

                return;

            case 'cancel':
                $this->state->clear($user);
                $screen = $this->crypto->cancelOrder((int) ($parts[1] ?? 0), (string) $user->chat_id);
                $this->render($update, $user, $screen['text'], $screen['kb']);

                return;

            case 'ok':
            case 'no':
                $this->decide($update, $user, (int) ($parts[1] ?? 0), $action === 'ok');

                return;
        }
    }

    /**
     * `crypto_net:<mode>:<net>`: build the order and arm the hash step.
     */
    private function createOrder(TelegramUpdate $update, User $user, string $mode, string $network): void
    {
        $result = $this->crypto->createOrder(
            chatId: (string) $user->chat_id,
            network: $network,
            mode: $mode,
            state: $this->state->get($user) ?? [],
        );

        if (! $result['ok'] || $result['order'] === null) {
            $this->render($update, $user, $result['text'], $result['kb']);

            return;
        }

        // The next message is the transaction hash of this order — the fork's
        // actionStep('set', ..., ['action' => 'crypto_hash', ...]).
        $this->state->set($user, [
            'action' => CryptoPayService::ACTION_HASH,
            CryptoPayService::STATE_ORDER_KEY => (int) $result['order']->id,
        ]);

        $screen = $this->crypto->orderScreen($result['order']);

        $this->render($update, $user, $screen['text'], $screen['kb']);
    }

    /**
     * `crypto_chk:<id>`: re-verify, but only for the owner or an admin.
     */
    private function recheck(TelegramUpdate $update, User $user, int $orderId): void
    {
        $order = CryptoPayment::query()->find($orderId);
        $guard = AdminGuard::fromSettings($this->settings);

        $allowed = $order !== null
            && ((string) $order->chat_id === (string) $user->chat_id
                || $guard->isAdmin($update->fromUserId() ?? $user->chat_id));

        if (! $allowed) {
            $this->render($update, $user, $this->crypto->msg('err_not_yours'), []);

            return;
        }

        $result = $this->crypto->verify($orderId);
        $text = $result['text'] !== '' ? $result['text'] : $this->crypto->statusText($order);

        if ($result['status'] !== CryptoPayService::STATUS_WAITING) {
            $this->state->clear($user);
        }

        $this->render($update, $user, $text, $result['kb'] ?? []);
    }

    /**
     * `crypto_ok:<id>` / `crypto_no:<id>`: the fork's admin gate.
     */
    private function decide(TelegramUpdate $update, User $user, int $orderId, bool $approve): void
    {
        $guard = AdminGuard::fromSettings($this->settings);
        $actor = $update->fromUserId() ?? $user->chat_id;

        if (! $guard->isAdmin($actor)) {
            $this->render($update, $user, self::FORBIDDEN, []);

            return;
        }

        $order = CryptoPayment::query()->find($orderId);
        $result = $this->crypto->adminDecide($orderId, $approve, 'bot:'.$actor);

        $key = $result['ok']
            ? ($approve ? 'admin_result_ok' : 'admin_result_no')
            : 'admin_result_err';

        $text = $order === null
            ? '⚠️ '.CryptoPayService::h($result['message'])
            : $this->crypto->msg($key, [
                'id' => (int) $order->id,
                'user' => $this->userLine($order),
                'order' => $this->orderLine($order),
                'amount' => number_format((int) $order->amount_toman),
                'message' => CryptoPayService::h($result['message']),
            ]);

        $doneLabel = $result['ok']
            ? ($approve ? '✅ | تایید شد' : '❌ | رد شد')
            : '⚠️ | بررسی شده';

        $this->render($update, $user, $text, [[['text' => $doneLabel, 'callback_data' => 'not']]]);
    }

    // -----------------------------------------------------------------
    // The crypto_hash text step
    // -----------------------------------------------------------------

    /**
     * Whatever the customer sends while an order waits for its hash: the
     * hash itself (bare or explorer link), a photo, or nothing useful.
     */
    private function handleHashInput(TelegramUpdate $update, User $user): void
    {
        $state = $this->state->get($user) ?? [];
        $orderId = isset($state[CryptoPayService::STATE_ORDER_KEY])
            ? (int) $state[CryptoPayService::STATE_ORDER_KEY]
            : null;

        $result = $this->crypto->submitHash(
            (string) $user->chat_id,
            $update->text(),
            $orderId,
        );

        if ($result['status'] !== CryptoPayService::STATUS_WAITING) {
            $this->state->clear($user);
        }

        $this->telegram->sendMessage((string) $user->chat_id, $result['text'], [
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode(['inline_keyboard' => $result['kb'] ?? []], JSON_UNESCAPED_UNICODE),
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Edit the callback message when there is one, otherwise send fresh —
     * the same rule WalletHandler follows.
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

    /** The fork's `userLine()`. */
    private function userLine(CryptoPayment $order): string
    {
        $profile = User::query()->where('chat_id', (string) $order->chat_id)->first();
        $telegram = $profile !== null && trim((string) $profile->telegram_id) !== ''
            ? '@'.ltrim((string) $profile->telegram_id, '@')
            : 'ندارد';
        $name = $profile !== null && trim((string) $profile->name) !== ''
            ? (string) $profile->name
            : 'نامشخص';

        return '👤 '.CryptoPayService::h($name).' ('.CryptoPayService::h($telegram).")\n🆔 <code>".CryptoPayService::h($order->chat_id).'</code>';
    }

    /** The fork's `orderLine()`. */
    private function orderLine(CryptoPayment $order): string
    {
        return $order->kind === 'buy'
            ? '🛍 خرید/تمدید سرویس (سفارش #'.(int) $order->payment_id.')'
            : '👝 افزایش موجودی کیف پول';
    }
}
