<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Services\Connectix\ConnectixService;
use App\Services\Plan\PlanService;
use App\Services\User\UserStateService;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The buy and renew step machine, up to but not including the panel call that
 * provisions the account.
 *
 * The presentation for each step lives in the handlers; this service owns the
 * decisions: which groups exist, which device counts a group offers, which
 * plans a device count has, what a plan costs, which panel client an account
 * maps to, and whether a wallet may pay for it.
 *
 * Port of the step half of `buy()`, `renew()` and `checkout()`.
 */
class PurchaseService
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly UserStateService $state,
        private readonly WalletService $wallets,
        private readonly ConnectixService $connectix,
    ) {}

    // -----------------------------------------------------------------
    // Catalogue
    // -----------------------------------------------------------------

    /**
     * The plan groups the bot offers, each with its sellable plan count.
     *
     * Port of `getSellerPlans('group')`, which also skipped any group that held
     * no plan at all.
     *
     * @return array<int, array{name: string, label: string, planCount: int}>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->plans->availableGroups() as $group) {
            $name = (string) ($group['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $planCount = count($this->plans->plansForGroup($name));

            if ($planCount === 0) {
                continue;
            }

            $groups[] = [
                'name' => $name,
                'label' => $this->plans->parseTypeWithEmoji($name),
                'planCount' => $planCount,
            ];
        }

        return $groups;
    }

    /**
     * Device counts available in a group, ascending.
     *
     * An empty result is the case legacy answered with the "هیچ پلن معتبری"
     * alert, so the caller can tell an empty group from an unknown one.
     *
     * @return array<int, int>
     */
    public function deviceCountsFor(string $group): array
    {
        return $this->plans->availableDeviceCounts($this->plans->plansForGroup($group));
    }

    /**
     * The sellable plans of a group for one device count.
     *
     * Port of the `count` branch of `buy()`, which compared the count loosely
     * and rendered the button as "traffic • period | price تومان".
     *
     * @return array<int, array{plan: array<string, mixed>, label: string, devices: int}>
     */
    public function plansForDeviceCount(string $group, int $deviceCount): array
    {
        $result = [];

        foreach ($this->plans->plansForGroup($group) as $plan) {
            $devices = (int) ($plan['count_of_devices'] ?? 0);

            if ($devices !== $deviceCount) {
                continue;
            }

            $details = $this->plans->parsePlanTitle((string) ($plan['title'] ?? ''));

            $traffic = ($details['traffic_gb'] ?? '') === '∞' ? 'نامحدود' : ($details['traffic_gb'] ?? '').' گیگ';
            $period = (string) ($details['period_text'] ?? '');

            $result[] = [
                'plan' => $plan,
                'label' => trim($traffic.' • '.$period).' | '.(string) ($plan['sell_price'] ?? '').' تومان',
                'devices' => $devices,
            ];
        }

        return $result;
    }

    // -----------------------------------------------------------------
    // State
    // -----------------------------------------------------------------

    /**
     * Remember the chosen group and move to the device count step.
     *
     * Port of the `group` branch of `buy()`, which kept the account the user
     * had already picked: a renewal that goes off to choose a different plan
     * comes back through here and must stay pointed at the same account.
     */
    public function chooseGroup(User $user, string $group): void
    {
        $state = $this->state->get($user) ?? [];

        $account = $state['acc'] ?? null;

        if (! $account) {
            $account = UserStateService::ACC_NEW;
        }

        $this->state->startPurchase($user, $group, (string) $account);
    }

    /**
     * Remember the chosen plan and its price, then move to the payment methods.
     *
     * The price comes from the panel, never from the callback, so a tampered
     * `buy_plan:` cannot pick its own amount. The account is kept as it is, so
     * the same call serves a new purchase and a renewal.
     *
     * @param  array<string, mixed>  $plan
     */
    public function choosePlan(User $user, array $plan): void
    {
        $state = $this->state->get($user) ?? [];

        $this->state->set($user, [
            'action' => $state['acc'] === UserStateService::ACC_NEW || ! isset($state['acc'])
                ? UserStateService::ACTION_BUY
                : UserStateService::ACTION_RENEW,
            'step' => 'plan',
            'acc' => $state['acc'] ?? UserStateService::ACC_NEW,
            'group' => $state['group'] ?? null,
            'plan' => (string) $plan['id'],
            'price' => (string) ($plan['sell_price'] ?? ''),
            'pay' => null,
        ]);
    }

    /**
     * Enter the card to card step.
     *
     * Port of the `card` branch of `checkout()`, which carried the account,
     * group, plan and price into the payment step untouched.
     */
    public function beginCardPayment(User $user, string $method = PaymentMethod::Card->value): void
    {
        $this->state->startPayment($user, $method);
    }

    /**
     * Enter the coupon step.
     *
     * Port of the `set` branch of `discount()`, which moved to the `discount`
     * action and kept the price so the cancel button could return to the card
     * screen with the right amount.
     */
    public function askForCoupon(User $user): void
    {
        $state = $this->state->get($user) ?? [];

        $this->state->set($user, [
            'action' => UserStateService::ACTION_DISCOUNT,
            'step' => 'set',
            'pay' => $state['pay'] ?? null,
            'acc' => $state['acc'] ?? null,
            'group' => $state['group'] ?? null,
            'plan' => $state['plan'] ?? null,
            'price' => $state['price'] ?? null,
        ]);
    }

    /**
     * Leave the coupon step once a code was applied, which legacy did by
     * restoring the whole payment state plus the discount figures.
     *
     * @param  array<string, mixed>  $state
     */
    public function applyCoupon(User $user, array $state): void
    {
        $this->state->set($user, $state);
    }

    // -----------------------------------------------------------------
    // Price
    // -----------------------------------------------------------------

    /**
     * The amount that will be stored on the order.
     *
     * Legacy stored the discounted value formatted with separators when a
     * coupon had been applied, and the raw panel price otherwise.
     *
     * @param  array<string, mixed>  $state
     */
    public function priceFor(array $state): string
    {
        if (isset($state['final_price'])) {
            return number_format($this->amountDue($state));
        }

        return (string) ($state['price'] ?? '');
    }

    /**
     * The amount as an integer, for comparisons and arithmetic.
     *
     * @param  array<string, mixed>  $state
     */
    public function amountDue(array $state): int
    {
        $value = $state['final_price'] ?? $state['price'] ?? null;

        if ($value === null) {
            return 0;
        }

        return (int) str_replace(',', '', (string) $value);
    }

    // -----------------------------------------------------------------
    // Accounts
    // -----------------------------------------------------------------

    /**
     * The user's accounts, oldest first.
     *
     * Legacy read the rows in insertion order and reversed them for the menus,
     * so newest first is what the user actually saw.
     *
     * @return Collection<int, Client>
     */
    public function accountsFor(User $user): Collection
    {
        return Client::query()
            ->where('chat_id', (string) $user->chat_id)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The same accounts, newest first, for the menus.
     *
     * @return array<int, Client>
     */
    public function accountsNewestFirst(User $user): array
    {
        return $this->accountsFor($user)->reverse()->values()->all();
    }

    /**
     * Whether the user already has an account, which decides whether the buy
     * menu offers renewal at all.
     */
    public function hasAccounts(User $user): bool
    {
        return $this->accountsFor($user)->isNotEmpty();
    }

    /**
     * The locally stored account with that username.
     */
    public function accountByUsername(string $username): ?Client
    {
        return Client::query()->where('username', $username)->first();
    }

    /**
     * The panel client id for the account held in state.
     *
     * A new account resolves to the literal 'new', the placeholder
     * `payments.client_id` holds until the account exists.
     *
     * The local row is preferred, and the panel is only asked when the account
     * is not linked locally. Legacy always asked the panel here; falling back to
     * the local row keeps the flow working when the panel is slow, and both
     * hold the same id because the row is written from the panel response.
     *
     * A renewal whose account cannot be resolved returns null instead of the
     * 'new' placeholder: answering with the placeholder would charge the buyer
     * for a renewal and hand them a second account, which is a different sale.
     *
     * @param  array<string, mixed>  $state
     */
    public function resolveClientId(array $state): ?string
    {
        $account = $state['acc'] ?? null;

        if ($account === null || $account === UserStateService::ACC_NEW) {
            return Payment::NEW_CLIENT;
        }

        $local = $this->accountByUsername((string) $account);

        if ($local !== null) {
            return (string) $local->id;
        }

        $remote = $this->connectix->getClientByUsername((string) $account);

        if ($remote === null || ($remote['id'] ?? '') === '') {
            Log::error('The account in state is neither linked locally nor known to the panel.', [
                'username' => $account,
            ]);

            return null;
        }

        return (string) $remote['id'];
    }

    // -----------------------------------------------------------------
    // Wallet
    // -----------------------------------------------------------------

    /**
     * The wallet button label, which carries the live balance.
     */
    public function walletLabel(User $user): string
    {
        $balance = number_format($this->wallets->balance($user->chat_id));

        return "👝 | کیف پول ( موجودی {$balance} تومان)";
    }

    /**
     * Whether the wallet can cover the amount, for the pre-check that avoids
     * opening a transaction only to fail.
     */
    public function walletCanCover(User $user, int $amount): bool
    {
        return $this->wallets->canAfford($user->chat_id, $amount);
    }
}
