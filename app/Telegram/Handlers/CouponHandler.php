<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Coupon\CouponService;
use App\Services\Purchase\PurchaseService;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

/**
 * The coupon code step of the checkout.
 *
 * Port of the `discount` action branch of bot.php, which called
 * `discount('apply')` once the validation ladder had passed.
 *
 * This handler is the only one that reads a plain text message while a state
 * is open, so it sits late in the registry: the purchase steps answer first.
 */
class CouponHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly CouponService $coupons,
        private readonly PurchaseService $purchase,
        private readonly MessageFactory $messages,
        private readonly UserStateService $state,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        if ($update->text() === null) {
            return false;
        }

        return ($this->state->get($user) ?? [])['action'] ?? null === UserStateService::ACTION_DISCOUNT;
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $state = $this->state->get($user) ?? [];

        $code = trim((string) $update->text());

        $reason = $this->coupons->rejectionReason($code, $state['plan'] ?? null);

        if ($reason !== null) {
            $this->reject($user, $reason, (string) ($state['price'] ?? ''));

            return;
        }

        $coupon = $this->coupons->findByCode($code);

        if ($coupon === null) {
            $this->reject($user, CouponService::ERROR_INVALID, (string) ($state['price'] ?? ''));

            return;
        }

        $result = $this->coupons->apply($coupon, (string) ($state['price'] ?? ''));

        if (! $result->isUsable()) {
            $this->reject($user, CouponService::ERROR_NO_DISCOUNT_VALUE, (string) ($state['price'] ?? ''));

            return;
        }

        $this->accept($user, $state, $result->finalPrice, $result->couponCode, $result->originalPrice, $result->discountAmount);
    }

    /**
     * Report a coupon that cannot be used, keeping the user in the coupon step.
     *
     * Port of the error branch of bot.php, which offered one way out: back to
     * the card screen with the amount that was already in state.
     */
    private function reject(User $user, string $reason, string $price): void
    {
        $this->telegram->sendMessage($user->chat_id, $reason, [
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '❌ | انصراف', 'callback_data' => 'pay_card:'.$price]],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Apply the coupon and show the card screen again with the new amount.
     *
     * Port of the `apply` branch of `discount()` plus the message bot.php sent
     * after it: the confirmation first, then the payment instructions.
     */
    private function accept(
        User $user,
        array $state,
        int $finalPrice,
        string $code,
        int $originalPrice,
        int $discountAmount,
    ): void {
        $formatted = number_format($finalPrice);

        $this->purchase->applyCoupon($user, [
            'action' => UserStateService::ACTION_PAY,
            'step' => 'pay',
            'pay' => $state['pay'] ?? null,
            'acc' => $state['acc'] ?? null,
            'group' => $state['group'] ?? null,
            'plan' => $state['plan'] ?? null,
            'price' => $finalPrice,
            'coupon_code' => $code,
            'original_price' => $originalPrice,
            'final_price' => $finalPrice,
        ]);

        $this->telegram->sendMessage(
            $user->chat_id,
            "🎟 کد تخفیف با موفقیت اعمال شد 🎉\n💰 مبلغ ".number_format($discountAmount).' تومان از فاکتور کسر گردید.',
        );

        $text = $this->messages->make('card', ['amount' => $formatted]);

        $this->telegram->sendMessage($user->chat_id, $text, [
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "🎟 | کد تخفیف « {$code} » اعمال شد", 'callback_data' => 'not']],
                    [['text' => '❌ | انصراف', 'callback_data' => 'main_menu']],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
