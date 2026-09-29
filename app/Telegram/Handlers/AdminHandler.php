<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Enums\PaymentStatus;
use App\Exceptions\TelegramApiException;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\Services\Plan\PlanService;
use App\Services\Purchase\ClientProvisioner;
use App\Services\Purchase\ProvisionResult;
use App\Services\Telegram\AdminGuard;
use App\Services\Telegram\TelegramService;
use App\Services\Wallet\DepositService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Support\Facades\Log;

/**
 * The administrator's answer to a forwarded receipt.
 *
 * Port of the `paycheck()` and `walletReqs()` accept/reject branches, reached
 * from the two buttons on the photo every receipt is sent with.
 *
 *  - `payment_accept:<id>` / `payment_reject:<id>` decide a purchase order.
 *    Accepting provisions the account through {@see ClientProvisioner}, which
 *    is also what the wallet route and the SMS auto-payment call, so every
 *    paid order ends up in the same place regardless of how it was paid.
 *  - `wallet_accept:<id>` / `wallet_reject:<id>` decide a wallet top-up
 *    through {@see DepositService}.
 *
 * The message that was edited is the receipt photo the administrator answered,
 * so each decision edits its own copy with the new status underneath the
 * image, exactly as legacy did.
 *
 * Legacy let anyone answer these buttons; a crafted update could therefore
 * approve an arbitrary order. The guard here refuses a non-administrator
 * silently, because a financial decision must not be reachable by every user.
 */
class AdminHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly AdminGuard $admins,
        private readonly PaymentService $payments,
        private readonly ClientProvisioner $provisioner,
        private readonly PlanService $plans,
        private readonly DepositService $deposits,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();

        if ($data === null) {
            return false;
        }

        if ($data === 'not') {
            return true;
        }

        return str_starts_with($data, 'payment_accept:')
            || str_starts_with($data, 'payment_reject:')
            || str_starts_with($data, 'wallet_accept:')
            || str_starts_with($data, 'wallet_reject:');
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = (string) $update->callbackData();

        if ($data === 'not') {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                '🤷🏻 این دکمه کاری انجام نمیده',
            );

            return;
        }

        if (! $this->admins->isAdmin($update->fromUserId())) {
            return;
        }

        [$action, $id] = $this->parseDecision($data);

        $result = match (true) {
            $action === 'payment_accept' => $this->acceptPayment((int) $id),
            $action === 'payment_reject' => $this->rejectPayment((int) $id),
            $action === 'wallet_accept' => $this->acceptDeposit((int) $id),
            default => $this->rejectDeposit((int) $id),
        };

        if ($result === null) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                'رکورد مورد نظر یافت نشد.',
            );

            return;
        }

        $this->editAdminPhoto($update, $result['caption'], $result['reply_markup']);
    }

    // -----------------------------------------------------------------
    // Purchase decisions
    // -----------------------------------------------------------------

    /**
     * An array shaped like legacy `paycheck('accept')` return values, with a
     * renderable caption.
     *
     * @return array{caption: string, reply_markup: string}|null
     */
    private function acceptPayment(int $paymentId): ?array
    {
        $payment = $this->payments->find($paymentId);

        if ($payment === null) {
            return null;
        }

        $result = $this->provisioner->provision($payment);

        if ($result->isAlreadyDecided()) {
            return $this->alreadyDecidedCaption($payment);
        }

        if ($result->isFailed()) {
            return null;
        }

        $username = $this->payments->buyer($payment)?->telegram_id ?? '';
        $clientUsername = (string) ($result->client['username'] ?? '');

        $caption = "✅ سفارش شماره <code>{$payment->order_number}</code> با موفقیت تایید شد\n\n"
            ."🪪 نام کاربری تلگرام: {$username} -> <a href='tg://user?id={$username}'>مشاهده</a>\n"
            ."👤 نام کاربری اکانت: <code>{$clientUsername}</code>\n"
            ."📦 پلن:\n {$this->planName($payment, $result)}\n"
            .'💵 مبلغ: '.$payment->price;

        return $this->captionResult($caption, '✅ | تایید شده');
    }

    /**
     * @return array{caption: string, reply_markup: string}|null
     */
    private function rejectPayment(int $paymentId): ?array
    {
        $payment = $this->payments->find($paymentId);

        if ($payment === null) {
            return null;
        }

        if ($this->payments->isDecided($payment)) {
            return $this->alreadyDecidedCaption($payment);
        }

        $this->payments->markRejected($paymentId);
        $this->notifyBuyerRejected($payment);

        $caption = "❌ سفارش شماره <code>{$payment->order_number}</code> تایید نشد\n\n"
            ."📦 پلن:\n {$this->planName($payment)}\n"
            .'💵 مبلغ: '.$payment->price;

        return $this->captionResult($caption, '❌ | تایید نشده');
    }

    /**
     * The caption for an order that was already decided, which is what a
     * second tap renders instead of acting twice.
     *
     * @return array{caption: string, reply_markup: string}
     */
    private function alreadyDecidedCaption(Payment $payment): array
    {
        [$icon, $name] = $payment->is_paid === PaymentStatus::Paid
            ? ['✅', 'تایید شده']
            : ['❌', 'رد شده'];

        $caption = "⚠️ سفارش شماره <code>{$payment->order_number}</code> در وضعیت {$name} است. ";

        return $this->captionResult($caption, "{$icon} | {$name}");
    }

    /**
     * Tell the buyer their payment was not approved.
     */
    private function notifyBuyerRejected(Payment $payment): void
    {
        $text = "❌پرداخت شما تایید نشد.\n"
            ."🛍 شماره سفارش: <code>{$payment->order_number}</code>\n\n"
            .' جهت اطلاع از وضعیت پرداخت، لطفا با پشتیبانی تماس بگیرید.';

        try {
            $this->telegram->sendMessage($payment->chat_id, $text, [
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '🏡 | خانه', 'callback_data' => 'main_menu']],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (TelegramApiException $e) {
            Log::warning('Could not tell the buyer their payment was rejected.', [
                'chat_id' => $payment->chat_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Deposit decisions
    // -----------------------------------------------------------------

    /**
     * @return array{caption: string, reply_markup: string}|null
     */
    private function acceptDeposit(int $transactionId): ?array
    {
        $outcome = $this->deposits->approve($transactionId);

        if ($outcome->isMissing()) {
            return null;
        }

        if ($outcome->isAlreadyDecided()) {
            return $this->deposits->decidedCaption($outcome->transaction);
        }

        return $this->deposits->approvedCaption($outcome);
    }

    /**
     * @return array{caption: string, reply_markup: string}|null
     */
    private function rejectDeposit(int $transactionId): ?array
    {
        $outcome = $this->deposits->reject($transactionId);

        if ($outcome->isMissing()) {
            return null;
        }

        if ($outcome->isAlreadyDecided()) {
            return $this->deposits->decidedCaption($outcome->transaction);
        }

        return $this->deposits->rejectedCaption($outcome);
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

    /**
     * The plan name legacy parsed for the admin captions.
     *
     * @param  array<string, mixed>  $client  The provisioned client, when there is one.
     */
    private function planName(Payment $payment, ?ProvisionResult $result = null): string
    {
        $clientPlans = $result?->client['plans'] ?? null;

        if (is_array($clientPlans) && isset($clientPlans[0]['name'])) {
            return (string) ($this->plans->parsePlanTitle((string) $clientPlans[0]['name'])['text'] ?? '');
        }

        $plan = $this->plans->findSellableById((string) $payment->plan_id);

        if ($plan !== null) {
            return (string) ($this->plans->parsePlanTitle((string) ($plan['title'] ?? ''))['text'] ?? '');
        }

        return (string) $payment->plan_id;
    }

    /**
     * Replace the caption of the receipt photo with the decision result.
     *
     * @param  array{caption: string, reply_markup: string}  $result
     */
    private function editAdminPhoto(TelegramUpdate $update, string $caption, string $replyMarkup): void
    {
        $messageId = $update->callbackMessageId();

        if ($messageId === null || $update->chatId() === null) {
            return;
        }

        try {
            $this->telegram->editMessageCaption($update->chatId(), $messageId, $caption, [
                'parse_mode' => 'HTML',
                'reply_markup' => $replyMarkup,
            ]);
        } catch (TelegramApiException $e) {
            Log::warning('Could not update the admin receipt message after a decision.', [
                'chat_id' => $update->chatId(),
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{caption: string, reply_markup: string}
     */
    private function captionResult(string $caption, string $button): array
    {
        return [
            'caption' => $caption,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => $button, 'callback_data' => 'not']],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Split `payment_accept:12` into `['payment_accept', '12']`.
     *
     * @return array{string, string}
     */
    private function parseDecision(string $data): array
    {
        $parts = explode(':', $data, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
