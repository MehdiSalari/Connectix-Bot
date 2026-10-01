<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\ConnectixApiException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Services\Connectix\ConnectixService;
use App\Services\Plan\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The read-only detail sheet behind one order row.
 *
 * The order list answers "which row?"; this answers "what is in it?". An
 * order is a `payments` row plus two things only the seller panel knows: the
 * plan that was bought and, once the account exists, the record of that
 * account. Both are read here on demand instead of being joined into the
 * list, because a panel round trip per row would turn a 20 row page into 20
 * HTTP calls - and because an order whose `client_id` is still the literal
 * `new` has no account to ask about at all.
 *
 * Nothing here mutates. The panel is read with the same endpoint the account
 * details modal already uses, and every field is reported absent when the
 * panel cannot answer rather than guessed.
 */
class AdminOrderDetailsController extends Controller
{
    public function __construct(
        private readonly ConnectixService $connectix,
        private readonly PlanService $plans,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(Request $request, Payment $payment): JsonResponse
    {
        $payment->loadMissing('user');

        /** @var User|null $buyer */
        $buyer = $payment->user;

        return response()->json([
            'order' => [
                'id' => $payment->id,
                'order_number' => $payment->order_number,
                'price_text' => number_format($payment->priceAmount()),
                'coupon' => $payment->coupon,
                'method' => $payment->method?->label(),
                'status' => $payment->is_paid->value,
                'status_label' => $payment->is_paid->label(),
                'created_at' => $payment->created_at?->format('Y-m-d H:i'),
            ],
            'buyer' => [
                'name' => $buyer?->name,
                'telegram' => $buyer?->telegram_id,
                'chat_id' => $payment->chat_id,
                'profile_url' => $this->profileUrl($buyer),
                // The cached Telegram avatar, so the modal header can show the
                // person the order belongs to instead of a bare letter.
                'avatar' => $buyer?->avatar,
            ],
            'plan' => $this->plan($payment),
            'account' => $this->account($payment),
        ]);
    }

    /**
     * The plan behind `plan_id`, split into the fields the sheet shows.
     *
     * `plan_id` is the only record of what was bought. The title grammar is
     * the same one the bot uses to render its own menu, so the volume,
     * duration and device count are read off the seller title instead of
     * being re-derived here.
     *
     * @return array<string, mixed>|null
     */
    private function plan(Payment $payment): ?array
    {
        $planId = trim((string) $payment->plan_id);

        if ($planId === '') {
            return null;
        }

        $plan = $this->plans->findSellableById($planId) ?? $this->plans->findById($planId);

        if ($plan === null) {
            // The catalogue is unreachable, or it no longer lists this plan.
            // Report the id and stop: a reconstructed title would be a guess,
            // and the order number is still enough to trace the row.
            return ['id' => $planId, 'title' => null, 'unavailable' => true];
        }

        $parsed = $this->plans->parsePlanTitle((string) ($plan['title'] ?? ''));

        return [
            'id' => $planId,
            'title' => (string) ($plan['title'] ?? ''),
            'devices' => $parsed['devices'] ?? null,
            'traffic' => $this->trafficLabel($parsed),
            'period' => $parsed['period_text'] ?? null,
            'type' => $this->plans->parseType($this->plans->groupNameFor($plan)),
            'extras' => $parsed['extras'] ?? [],
        ];
    }

    /**
     * The account the order provisioned, when the panel can be asked for it.
     *
     * @return array<string, mixed>|null
     */
    private function account(Payment $payment): ?array
    {
        if ($payment->isNewClient()) {
            // Nothing was provisioned yet - the account is created when the
            // order is approved, not when it is placed.
            return null;
        }

        try {
            $client = $this->connectix->getClientData((string) $payment->client_id);
        } catch (ConnectixApiException $e) {
            Log::warning('Order account details could not be loaded from the panel.', [
                'payment_id' => $payment->id,
                'client_id' => $payment->client_id,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'دریافت اطلاعات اکانت از پنل Connectix ممکن نشد.'];
        }

        if ($client === null) {
            return ['error' => 'اکانتی با این شناسه در پنل پیدا نشد.'];
        }

        $running = $this->runningPlan($client);

        return [
            'id' => (string) ($client['id'] ?? $payment->client_id),
            'name' => $client['name'] ?? null,
            'username' => $client['username'] ?? null,
            'email' => $client['email'] ?? null,
            'password' => $client['password'] ?? null,
            'expire_date' => $client['expire_date'] ?? null,
            'devices' => $client['count_of_devices'] ?? null,
            'used_traffic' => $running['used_traffic'] ?? null,
            'total_traffic' => $running['total_traffic'] ?? null,
            'subscription_link' => $client['subscription_link'] ?? null,
        ];
    }

    /**
     * The plan currently running on the account, and its traffic pair.
     *
     * The panel flags are the rule the legacy bot's own account list used: the
     * first active plan, otherwise the first queued one - a queued plan is one
     * the panel has accepted and will start, so it is the right answer for an
     * order that is still waiting to be activated.
     *
     * The panel reports only what has been burned ("1.4 گیگ"). The ceiling is
     * read from the running plan's own title, which is the only place the
     * quota is written down, so the sheet can show the 1.4/30 pair.
     *
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>
     */
    private function runningPlan(array $client): array
    {
        $plans = isset($client['plans']) && is_array($client['plans']) ? $client['plans'] : [];

        $fallback = null;

        foreach ($plans as $plan) {
            if (! is_array($plan)) {
                continue;
            }

            if ((int) ($plan['is_active'] ?? 0) === 1) {
                return $this->trafficOf($plan);
            }

            if ($fallback === null && ! empty($plan['is_in_queue'])) {
                $fallback = $plan;
            }
        }

        return $fallback === null ? [] : $this->trafficOf($fallback);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function trafficOf(array $plan): array
    {
        $used = trim((string) ($plan['total_used_traffic'] ?? ''));
        $title = (string) ($plan['name'] ?? $plan['title'] ?? '');
        $parsed = $this->plans->parsePlanTitle($title);

        // The quota is read out of the plan's own title, which is the only
        // place it is written down - the panel reports only what has been
        // burned. A title the grammar does not recognise (a short form, or a
        // name the seller typed by hand) yields no ceiling, and the sheet then
        // reports only what is known instead of inventing "30".
        $total = $this->trafficLabel($parsed);

        if ($total === null && trim($title) !== '') {
            Log::debug('A running plan title did not parse into a quota.', ['title' => $title]);
        }

        return [
            'used_traffic' => $used === '' ? null : $used,
            'total_traffic' => $total,
        ];
    }

    /**
     * Human readable quota from a parsed plan title, or null when the title
     * does not state one.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function trafficLabel(array $parsed): ?string
    {
        $traffic = $parsed['traffic_gb'] ?? null;

        if ($traffic === null) {
            return null;
        }

        return (string) $traffic === '∞' ? 'نامحدود' : "{$traffic} GB";
    }

    /**
     * A link to the buyer's public Telegram profile.
     */
    private function profileUrl(?User $buyer): ?string
    {
        $telegram = trim((string) ($buyer?->telegram_id ?? ''));

        return $telegram === '' ? null : 'https://t.me/'.ltrim($telegram, '@');
    }
}
