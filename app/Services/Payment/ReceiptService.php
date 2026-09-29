<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Enums\SmsPaymentType;
use App\Exceptions\TelegramApiException;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Panel\PanelSettingsService;
use App\Services\Plan\PlanService;
use App\Services\Purchase\ClientProvisioner;
use App\Services\Purchase\PurchaseService;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Services\Wallet\DepositService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Log;

/**
 * What happens when a payment receipt photo arrives.
 *
 * Port of `payment()` in functions.php plus the wallet deposit half of bot.php
 * that runs directly before it.
 *
 * For a card purchase the order does not exist yet: it is written now, from
 * the account, plan, price and coupon held in the conversation state, and the
 * receipt is forwarded to every administrator with approve/reject buttons. A
 * wallet top-up gets a pending `wallet_transactions` row for the same stage.
 *
 * When a bank is configured, the amount is first matched against an inbound
 * SMS deposit: a unique match settles the order immediately (provisioning a
 * card purchase, crediting the wallet for a deposit) and skips the admin
 * round-trip entirely, which is what legacy called auto-payment.
 *
 * Receipts are never downloaded or stored: the Telegram `file_id` is
 * forwarded to the administrators as-is, exactly as legacy forwarded the last
 * photo size it received.
 */
class ReceiptService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly WalletService $wallets,
        private readonly PurchaseService $purchase,
        private readonly PlanService $plans,
        private readonly SmsPaymentService $sms,
        private readonly DepositService $deposits,
        private readonly ClientProvisioner $provisioner,
        private readonly PanelSettingsService $settings,
        private readonly TelegramService $telegram,
        private readonly UserStateService $state,
    ) {}

    // -----------------------------------------------------------------
    // Card purchase
    // -----------------------------------------------------------------

    /**
     * Write the order a card receipt belongs to and route it for payment.
     *
     * @param  array<string, mixed>  $state
     */
    public function submitPurchase(User $user, array $state, string $fileId): ?Payment
    {
        $plan = $this->plans->findSellableById((string) ($state['plan'] ?? ''));

        if ($plan === null) {
            Log::error('A receipt arrived for a plan the panel no longer sells.', [
                'chat_id' => (string) $user->chat_id,
                'plan_id' => $state['plan'] ?? null,
            ]);

            return null;
        }

        $clientId = $this->purchase->resolveClientId($state);

        if ($clientId === null) {
            Log::error('A receipt arrived for an account that cannot be resolved.', [
                'chat_id' => (string) $user->chat_id,
                'acc' => $state['acc'] ?? null,
            ]);

            return null;
        }

        $method = PaymentMethod::tryFrom((string) ($state['pay'] ?? ''));

        if ($method === null) {
            Log::error('A receipt arrived with an unknown payment method in state.', [
                'chat_id' => (string) $user->chat_id,
                'pay' => $state['pay'] ?? null,
            ]);

            return null;
        }

        $price = $this->purchase->priceFor($state);
        $coupon = $state['coupon_code'] ?? null;

        $payment = $this->payments->create(
            chatId: $user->chat_id,
            clientId: $clientId,
            planId: (string) $plan['id'],
            price: $price,
            method: $method,
            status: null,
            coupon: $coupon !== null ? (string) $coupon : null,
        );

        if ($payment === null) {
            return null;
        }

        if ($this->claimAndSettlePurchase($payment, $plan, $state, $user)) {
            // The deposit paid for the whole order: the account is provisioned
            // and the admin round-trip is skipped.
            return $payment;
        }

        $this->notifyReceiptReceived(
            $user,
            "✅ سند پرداخت شما با موفقیت دریافت شد.\n\n📦 پلن انتخابی شما:\n "
            .$this->planName($plan)."\n\n⌛ لطفا منتظر تایید بمانید."
        );

        $this->forwardReceipt($fileId, $this->purchaseCaption($payment, $plan, $state), [
            [
                ['text' => '❌ |  رد', 'callback_data' => 'payment_reject:'.$payment->id],
                ['text' => '✅ |  تایید', 'callback_data' => 'payment_accept:'.$payment->id],
            ],
        ]);

        $this->state->clear($user);

        return $payment;
    }

    // -----------------------------------------------------------------
    // Wallet deposit
    // -----------------------------------------------------------------

    /**
     * Open a pending wallet top-up and route it for payment.
     */
    public function submitDeposit(User $user, int $amount, string $fileId): ?WalletTransaction
    {
        $transaction = $this->wallets->createPendingDeposit((string) $user->chat_id, $amount);

        if ($transaction === null) {
            return null;
        }

        $this->state->set($user, [
            'action' => UserStateService::ACTION_WALLET_INCREASE,
            'step' => 'pending',
            'amount' => $amount,
            'txID' => $transaction->id,
        ]);

        if ($this->claimAndSettleDeposit($transaction, $user)) {
            // The deposit paid for itself: the balance is credited and the
            // admin round-trip is skipped.
            return $transaction;
        }

        $this->notifyReceiptReceived(
            $user,
            "✅ سند پرداخت شما با موفقیت دریافت شد.\n\n💰 افزایش موجودی کیف پول:\n"
            .'💵 مبلغ : '.number_format($amount)."\n\n⌛ لطفا منتظر تایید بمانید."
        );

        $this->forwardReceipt($fileId, $this->depositCaption($transaction, $user), [
            [
                ['text' => '❌ |  رد', 'callback_data' => 'wallet_reject:'.$transaction->id],
                ['text' => '✅ |  تایید', 'callback_data' => 'wallet_accept:'.$transaction->id],
            ],
        ]);

        return $transaction;
    }

    // -----------------------------------------------------------------
    // Auto-payment
    // -----------------------------------------------------------------

    /**
     * Try to settle a card order against an inbound SMS deposit.
     *
     * Port of the auto-payment branch of `payment('buy')`: matching a unique
     * deposit of the exact amount provisions the order and links the deposit
     * to it. The link is conditional, so an order that another checkout
     * already claimed cannot be settled twice.
     */
    private function claimAndSettlePurchase(Payment $payment, array $plan, array $state, User $user): bool
    {
        if (! $this->sms->isEnabled()) {
            return false;
        }

        $deposit = $this->sms->claim($this->purchase->amountDue($state));

        if ($deposit === null) {
            return false;
        }

        $result = $this->provisioner->provision($payment);

        if (! $result->isProvisioned()) {
            Log::error('Auto-payment could not provision the order; the SMS stays unmatched.', [
                'payment_id' => $payment->id,
                'sms_id' => $deposit->id,
                'reason' => $result->reason,
            ]);

            return false;
        }

        $this->sms->link($deposit, (int) $payment->id, SmsPaymentType::Buy);

        $this->state->clear($user);

        return true;
    }

    /**
     * Try to settle a wallet deposit against an inbound SMS deposit.
     *
     * Port of the auto-payment branch of `payment('wallet')`: matching a
     * unique deposit credits the wallet, tells the buyer and links the
     * deposit to the transaction.
     */
    private function claimAndSettleDeposit(WalletTransaction $transaction, User $user): bool
    {
        if (! $this->sms->isEnabled()) {
            return false;
        }

        $deposit = $this->sms->claim((int) $transaction->amount);

        if ($deposit === null) {
            return false;
        }

        $outcome = $this->deposits->approve((int) $transaction->id);

        if (! $outcome->isApproved()) {
            Log::error('Auto-payment could not approve the wallet deposit; the SMS stays unmatched.', [
                'transaction_id' => $transaction->id,
                'sms_id' => $deposit->id,
            ]);

            return false;
        }

        $this->sms->link($deposit, (int) $transaction->id, SmsPaymentType::Wallet);

        $this->state->clear($user);

        return true;
    }

    // -----------------------------------------------------------------
    // Admin forwarding
    // -----------------------------------------------------------------

    /**
     * The caption of the receipt photo sent to the administrators.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $state
     */
    private function purchaseCaption(Payment $payment, array $plan, array $state): string
    {
        $caption = "📃 سند واریزی مورد تایید میباشد?\n\n"
            ."📦 پلن: {$this->planName($plan)}\n"
            .'💸 مبلغ واریزی: '.$payment->price;

        if (! empty($state['coupon_code'])) {
            $caption .= "\n💵 مبلغ اصلی: ".number_format((int) ($state['original_price'] ?? 0));
            $caption .= "\n🎟 کد تخفیف استفاده شده: ".$state['coupon_code'];
        }

        return $caption;
    }

    /**
     * The caption of the receipt photo for a wallet top-up.
     */
    private function depositCaption(WalletTransaction $transaction, User $user): string
    {
        $userName = $user->telegram_id !== null && trim((string) $user->telegram_id) !== ''
            ? '@'.$user->telegram_id
            : 'نامشخص';

        return "📃 سند واریزی مورد تایید میباشد?\n\n"
            ."💰 افزایش موجودی کیف پول:\n"
            ."🔢 آیدی: <code>{$user->chat_id}</code>\n"
            ."👤 نام کاربری: {$userName}\n"
            .'💵 مبلغ : '.number_format((int) $transaction->amount);
    }

    /**
     * Send the receipt photo to every administrator with a decision keyboard.
     *
     * @param  array<int, array<int, array<string, string>>>  $keyboard
     */
    private function forwardReceipt(string $fileId, string $caption, array $keyboard): bool
    {
        $sent = false;

        foreach ($this->settings->adminIds() as $adminId) {
            try {
                $this->telegram->sendPhoto($adminId, $fileId, [
                    'caption' => $caption,
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
                ]);

                $sent = true;
            } catch (TelegramApiException $e) {
                Log::warning('Failed to forward a receipt to an administrator.', [
                    'admin_id' => $adminId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($this->settings->adminIds() === []) {
            Log::warning('A payment receipt arrived but no administrator is configured.', [
                'caption' => $caption,
            ]);
        }

        return $sent;
    }

    /**
     * Send a one-off message to the buyer.
     */
    private function notifyReceiptReceived(User $user, string $text): void
    {
        try {
            $this->telegram->sendMessage($user->chat_id, $text);
        } catch (TelegramApiException $e) {
            Log::warning('Could not confirm the receipt to the buyer.', [
                'chat_id' => (string) $user->chat_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The plan name legacy parsed out of the panel title.
     *
     * @param  array<string, mixed>  $plan
     */
    private function planName(array $plan): string
    {
        return (string) ($this->plans->parsePlanTitle((string) ($plan['title'] ?? ''))['text'] ?? '');
    }
}
