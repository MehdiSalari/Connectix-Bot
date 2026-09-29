<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Central access point for the conversation state machine.
 *
 * Legacy stored this state as a raw JSON blob in `users.action` and read it
 * with `actionStep('get'|'set'|'clear')` from about twenty call sites, each of
 * which accessed array keys directly. This service owns the encoding, decoding
 * and the key names so the shape is defined in exactly one place.
 *
 * The recognised keys are:
 *  - action         current flow: pay, buy, renew, discount, wallet_increase, add_account
 *  - step           sub-step within the flow
 *  - pay            payment method (card|wallet)
 *  - acc            'new' or the client username
 *  - group          seller plan group name
 *  - plan           seller plan id
 *  - price          order price as returned by the panel
 *  - coupon_code    applied coupon, if any
 *  - original_price price before the discount
 *  - final_price    price after the discount
 *  - amount         wallet deposit amount
 *  - txID           pending wallet transaction id
 *  - username       account username being linked
 */
class UserStateService
{
    public const ACTION_PAY = 'pay';

    public const ACTION_BUY = 'buy';

    public const ACTION_RENEW = 'renew';

    public const ACTION_DISCOUNT = 'discount';

    public const ACTION_WALLET_INCREASE = 'wallet_increase';

    public const ACTION_ADD_ACCOUNT = 'add_account';

    /**
     * Sentinel stored in `acc` when a brand new account is being purchased.
     */
    public const ACC_NEW = 'new';

    /**
     * Read the decoded state.
     *
     * @return array<string, mixed>|null Null matches legacy, which returned
     *                                   false for an empty or missing blob.
     */
    public function get(User $user): ?array
    {
        $raw = $user->action;

        if (blank($raw)) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        return $decoded;
    }

    /**
     * Read a single key with a fallback, mirroring the loose array access of
     * the legacy code (`$actionData['plan'] ?? null`).
     */
    public function getValue(User $user, string $key, mixed $default = null): mixed
    {
        return $this->get($user)[$key] ?? $default;
    }

    /**
     * Whether the user is currently inside a card-payment receipt step.
     *
     * Legacy bot.php used this to decide that the next message must be a
     * photo, with `discount` deliberately excluded.
     */
    public function awaitingReceipt(User $user): bool
    {
        $state = $this->get($user);

        if ($state === null) {
            return false;
        }

        $pay = $state['pay'] ?? null;
        $action = $state['action'] ?? null;

        return (bool) $pay && $action !== self::ACTION_DISCOUNT;
    }

    /**
     * Replace the whole state.
     *
     * @param  array<string, mixed>  $state
     */
    public function set(User $user, array $state): void
    {
        $user->forceFill(['action' => json_encode($state, JSON_UNESCAPED_UNICODE)])->save();
    }

    /**
     * Merge keys into the current state, keeping the untouched ones.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> The resulting state.
     */
    public function update(User $user, array $values): array
    {
        $state = array_merge($this->get($user) ?? [], $values);

        $this->set($user, $state);

        return $state;
    }

    /**
     * Reset the state, which is what legacy `actionStep('clear')` did.
     */
    public function clear(User $user): void
    {
        $user->forceFill(['action' => null])->save();
    }

    /**
     * Start the renewal flow for a specific account.
     */
    public function startRenewal(User $user, string $username): void
    {
        $this->set($user, [
            'action' => self::ACTION_RENEW,
            'step' => 'acc',
            'acc' => $username,
            'group' => null,
            'plan' => null,
            'pay' => null,
        ]);
    }

    /**
     * Start the purchase flow for a plan group.
     */
    public function startPurchase(User $user, string $group, string $account = self::ACC_NEW): void
    {
        $this->set($user, [
            'action' => self::ACTION_BUY,
            'step' => 'group',
            'acc' => $account,
            'group' => $group,
            'plan' => null,
            'pay' => null,
        ]);
    }

    /**
     * Move into the receipt step after a payment method was chosen.
     *
     * @param  array<string, mixed>  $extra
     */
    public function startPayment(User $user, string $method, array $extra = []): void
    {
        $state = $this->get($user) ?? [];

        $this->set($user, array_merge([
            'action' => self::ACTION_PAY,
            'step' => 'pay',
            'pay' => $method,
            'acc' => $state['acc'] ?? null,
            'group' => $state['group'] ?? null,
            'plan' => $state['plan'] ?? null,
            'price' => $state['price'] ?? null,
        ], $extra));
    }

    /**
     * Start collecting a wallet deposit amount.
     */
    public function startWalletIncrease(User $user): void
    {
        $this->set($user, [
            'action' => self::ACTION_WALLET_INCREASE,
            'step' => 'get_amount',
            'amount' => null,
        ]);
    }

    /**
     * Start the "link an existing account" flow.
     */
    public function startAddAccount(User $user, ?string $username = null): void
    {
        $this->set($user, [
            'action' => self::ACTION_ADD_ACCOUNT,
            'step' => 'get_username',
            'username' => $username,
        ]);
    }

    /**
     * Discard state that references a flow the user is no longer in.
     *
     * Failures here are logged rather than thrown: losing a state row must
     * never break an otherwise successful reply.
     */
    public function tryClear(User $user): void
    {
        try {
            $this->clear($user);
        } catch (\Throwable $e) {
            Log::warning('Failed to clear user conversation state.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
