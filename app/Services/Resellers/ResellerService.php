<?php

declare(strict_types=1);

namespace App\Services\Resellers;

use App\Models\PanelSetting;
use App\Models\Reseller;
use App\Models\ResellerRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Panel\PanelSettingsService;
use App\Services\Telegram\AdminGuard;
use App\Services\Telegram\TelegramService;
use App\Support\JalaliDate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The representative (نمایندگی) registry and its panel-side management.
 *
 * Port of the fork's services/ResellerService.php behind reseller/index.php:
 * a user asks to become a representative, the admin approves or rejects the
 * request here, and approved representatives carry a test-account quota plus
 * a discount that the checkout applies.
 *
 * Configuration lives in `panel_settings` under the keys this service owns —
 * the database equivalent of the fork's `reseller` block in
 * setup/bot_config.json. A representative row may override the default quota
 * (`test_limit`: NULL follows the default, -1 is unlimited) and the default
 * discount (stored as JSON in the row's `notes` column, the only free field
 * the legacy schema leaves for it).
 */
class ResellerService
{
    /**
     * The panel settings this service owns, with their shipped defaults.
     *
     * @var array<string, string>
     */
    private const SETTING_DEFAULTS = [
        'reseller_min_wallet_balance' => '0',
        'reseller_default_test_limit' => '1',
        'reseller_default_test_unlimited' => '0',
        'reseller_monthly_quota' => '0',
        'reseller_default_discount_type' => 'percent',
        'reseller_default_discount_value' => '0',
    ];

    /** The discount shape a representative (or the default) carries. */
    public const DISCOUNT_TYPES = ['percent', 'fixed'];

    /**
     * Memoised settings for the current request; saveSettings refreshes it.
     */
    private ?array $settingsResolved = null;

    public function __construct(private readonly TelegramService $telegram) {}

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    /**
     * The reseller configuration, resolved from `panel_settings`.
     *
     * @return array{
     *     reseller_min_wallet_balance: float,
     *     reseller_default_test_limit: int,
     *     reseller_default_test_unlimited: bool,
     *     reseller_monthly_quota: bool,
     *     reseller_default_discount_type: string,
     *     reseller_default_discount_value: float,
     * }
     */
    public function settings(): array
    {
        if ($this->settingsResolved !== null) {
            return $this->settingsResolved;
        }

        $stored = PanelSetting::query()
            ->whereIn('setting_key', array_keys(self::SETTING_DEFAULTS))
            ->pluck('setting_value', 'setting_key');

        $type = in_array($stored->get('reseller_default_discount_type'), self::DISCOUNT_TYPES, true)
            ? (string) $stored->get('reseller_default_discount_type')
            : 'percent';

        return $this->settingsResolved = [
            'reseller_min_wallet_balance' => (float) ($stored->get('reseller_min_wallet_balance') ?? '0'),
            'reseller_default_test_limit' => max(0, (int) ($stored->get('reseller_default_test_limit') ?? '1')),
            'reseller_default_test_unlimited' => filter_var(
                (string) $stored->get('reseller_default_test_unlimited', '0'),
                FILTER_VALIDATE_BOOL,
            ),
            'reseller_monthly_quota' => filter_var(
                (string) $stored->get('reseller_monthly_quota', '0'),
                FILTER_VALIDATE_BOOL,
            ),
            'reseller_default_discount_type' => $type,
            'reseller_default_discount_value' => (float) ($stored->get('reseller_default_discount_value') ?? '0'),
        ];
    }

    /**
     * Persist the admin settings page as `panel_settings` rows.
     *
     * Absent keys keep their current value, so one form posting a subset
     * cannot silently reset the rest.
     *
     * @param  array<string, mixed>  $values
     */
    public function saveSettings(array $values): void
    {
        $current = $this->settings();

        $stored = [
            'reseller_min_wallet_balance' => (string) max(
                0.0,
                (float) ($values['reseller_min_wallet_balance'] ?? $current['reseller_min_wallet_balance']),
            ),
            'reseller_default_test_limit' => (string) max(
                0,
                (int) ($values['reseller_default_test_limit'] ?? $current['reseller_default_test_limit']),
            ),
            'reseller_default_test_unlimited' => ! empty($values['reseller_default_test_unlimited']) ? '1' : '0',
            'reseller_monthly_quota' => ! empty($values['reseller_monthly_quota']) ? '1' : '0',
            'reseller_default_discount_type' => ($values['reseller_default_discount_type'] ?? 'percent') === 'fixed'
                ? 'fixed'
                : 'percent',
            'reseller_default_discount_value' => (string) max(
                0.0,
                (float) ($values['reseller_default_discount_value'] ?? $current['reseller_default_discount_value']),
            ),
        ];

        foreach ($stored as $key => $value) {
            PanelSetting::query()->updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => $value, 'updated_at' => now()],
            );
        }

        $this->settingsResolved = null;
    }

    // -----------------------------------------------------------------
    // Listings and stats
    // -----------------------------------------------------------------

    /**
     * The representatives, newest first, searchable by chat id.
     *
     * Each row is decorated with the display values the panel needs (the
     * applicant's cached name, its effective quota and discount) so the view
     * renders without issuing its own queries.
     */
    public function listResellers(string $search = ''): LengthAwarePaginator
    {
        $resellers = Reseller::query()
            ->when($search !== '', fn ($query) => $query->where('chat_id', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        if ($resellers->isNotEmpty()) {
            $users = $this->usersByChatId($resellers->getCollection()->pluck('chat_id'));

            $resellers->getCollection()->each(function (Reseller $reseller) use ($users): void {
                $this->decorate($reseller, $users);
            });
        }

        return $resellers;
    }

    /**
     * The representative requests still awaiting a decision.
     *
     * @return Collection<int, ResellerRequest>
     */
    public function openRequests(): Collection
    {
        $requests = ResellerRequest::query()
            ->where('status', 'PENDING')
            ->orderByDesc('id')
            ->get();

        if ($requests->isEmpty()) {
            return $requests;
        }

        $users = $this->usersByChatId($requests->pluck('chat_id'));

        return $requests->each(function (ResellerRequest $request) use ($users): void {
            $request->setAttribute('user_name', $users[$request->chat_id]->name ?? null);
            $request->setAttribute('created_at_text', $this->timeText($request->created_at));
        });
    }

    /**
     * The counters the page header shows.
     *
     * @return array{active: int, pending: int, total_balance: float}
     */
    public function stats(): array
    {
        return [
            'active' => (int) Reseller::query()->where('status', 'ACTIVE')->count(),
            'pending' => (int) ResellerRequest::query()->where('status', 'PENDING')->count(),
            'total_balance' => (float) Reseller::query()->sum('balance'),
        ];
    }

    // -----------------------------------------------------------------
    // Quota
    // -----------------------------------------------------------------

    /**
     * Set one representative's free-test quota.
     *
     * $limit null follows the global default, -1 means unlimited, any other
     * value is the number of free tests. $monthly overrides how the used
     * counter resets for this row ('monthly' | 'total').
     */
    public function setTestQuota(Reseller $reseller, ?int $limit, bool $monthly = false): void
    {
        $reseller->test_limit = $limit === null ? null : max(-1, $limit);
        $reseller->test_period_mode = $monthly ? 'monthly' : 'total';
        $reseller->updated_at = now();
        $reseller->save();
    }

    /**
     * Clear a representative's used-test counter.
     */
    public function resetTestUsed(Reseller $reseller): void
    {
        $reseller->test_used = 0;
        $reseller->test_period_key = $this->currentPeriodKey();
        $reseller->updated_at = now();
        $reseller->save();
    }

    /**
     * The effective quota: -1 unlimited, else a number of free tests.
     */
    public function effectiveTestLimit(Reseller $reseller): int
    {
        if ($reseller->test_limit === null) {
            $settings = $this->settings();

            return $settings['reseller_default_test_unlimited']
                ? -1
                : $settings['reseller_default_test_limit'];
        }

        return max(-1, (int) $reseller->test_limit);
    }

    /**
     * Whether this row's quota resets every Jalali month.
     */
    public function isMonthly(Reseller $reseller): bool
    {
        if ($reseller->test_period_mode === 'monthly' || $reseller->test_period_mode === 'total') {
            return $reseller->test_period_mode === 'monthly';
        }

        return $this->settings()['reseller_monthly_quota'];
    }

    /**
     * Free tests the row has spent in its counting window: in monthly mode a
     * counter from an older period reads as zero.
     */
    public function effectiveUsed(Reseller $reseller): int
    {
        $used = (int) $reseller->test_used;

        if ($this->isMonthly($reseller) && $reseller->test_period_key !== $this->currentPeriodKey()) {
            return 0;
        }

        return $used;
    }

    /**
     * The current Jalali year-month, e.g. "1405-07" (Tehran time).
     */
    public function currentPeriodKey(): string
    {
        $today = JalaliDate::today();

        return sprintf('%04d-%02d', $today['year'], $today['month']);
    }

    // -----------------------------------------------------------------
    // Discount
    // -----------------------------------------------------------------

    /**
     * The discount a representative carries: their own override when one was
     * saved, the panel default otherwise.
     *
     * @return array{type: string, value: float, custom: bool}
     */
    public function discountFor(Reseller $reseller): array
    {
        $notes = trim((string) $reseller->notes);

        if ($notes !== '') {
            $decoded = json_decode($notes, true);

            if (is_array($decoded)
                && isset($decoded['discount_type'], $decoded['discount_value'])
                && in_array($decoded['discount_type'], self::DISCOUNT_TYPES, true)
                && is_numeric($decoded['discount_value'])
            ) {
                return [
                    'type' => (string) $decoded['discount_type'],
                    'value' => (float) $decoded['discount_value'],
                    'custom' => true,
                ];
            }
        }

        $settings = $this->settings();

        return [
            'type' => $settings['reseller_default_discount_type'],
            'value' => $settings['reseller_default_discount_value'],
            'custom' => false,
        ];
    }

    /**
     * Save a per-representative discount override.
     *
     * The JSON rides in the row's `notes` column — the schema's only free
     * field — and `commission_rate` keeps the percent shape of the value for
     * anything that still reads the legacy column.
     */
    public function setDiscount(Reseller $reseller, string $type, float $value): void
    {
        $reseller->notes = json_encode(
            ['discount_type' => $type === 'fixed' ? 'fixed' : 'percent', 'discount_value' => max(0.0, $value)],
            JSON_UNESCAPED_UNICODE,
        );
        $reseller->commission_rate = $type === 'fixed' ? 0 : max(0.0, $value);
        $reseller->updated_at = now();
        $reseller->save();
    }

    // -----------------------------------------------------------------
    // Requests
    // -----------------------------------------------------------------

    /**
     * Approve a pending request: mark it APPROVED and activate (or create)
     * the representative row for that chat, in one transaction.
     */
    public function approveRequest(ResellerRequest $resellerRequest, string $processedBy): bool
    {
        if (! $resellerRequest->isPending()) {
            return false;
        }

        $settings = $this->settings();
        $commissionRate = $settings['reseller_default_discount_type'] === 'percent'
            ? $settings['reseller_default_discount_value']
            : 0.0;

        DB::transaction(function () use ($resellerRequest, $processedBy, $commissionRate): void {
            $resellerRequest->status = 'APPROVED';
            $resellerRequest->processed_by = $processedBy;
            $resellerRequest->updated_at = now();
            $resellerRequest->save();

            $reseller = Reseller::query()->where('chat_id', $resellerRequest->chat_id)->first();

            if ($reseller === null) {
                $reseller = new Reseller([
                    'user_id' => $resellerRequest->user_id,
                    'chat_id' => (string) $resellerRequest->chat_id,
                    'ref_code' => 'rep_'.substr(md5($resellerRequest->chat_id.microtime()), 0, 8),
                ]);
            }

            $reseller->status = 'ACTIVE';
            $reseller->commission_rate = $commissionRate;
            $reseller->updated_at = now();
            $reseller->save();
        });

        $this->notify(
            (string) $resellerRequest->chat_id,
            "✅ <b>درخواست نمایندگی شما تایید شد!</b>\n\nاز این پس تخفیف‌های ویژه نمایندگان، به‌صورت خودکار در خرید و تمدید پلن‌ها برای شما اعمال می‌شود.",
        );

        return true;
    }

    /**
     * Reject a pending request, recording the admin's note.
     */
    public function rejectRequest(ResellerRequest $resellerRequest, ?string $note, string $processedBy): bool
    {
        if (! $resellerRequest->isPending()) {
            return false;
        }

        $resellerRequest->status = 'REJECTED';
        $resellerRequest->admin_note = $note;
        $resellerRequest->processed_by = $processedBy;
        $resellerRequest->updated_at = now();
        $resellerRequest->save();

        $this->notify(
            (string) $resellerRequest->chat_id,
            '<b>درخواست نمایندگی شما رد شد.</b>'.($note !== null && $note !== '' ? "\n\nدلیل: {$note}" : ''),
        );

        return true;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Attach the display values a representative row needs to the view.
     *
     * @param  Collection<string, User>  $users
     */
    private function decorate(Reseller $reseller, Collection $users): void
    {
        $reseller->setAttribute('user_name', $users[$reseller->chat_id]->name ?? null);
        $reseller->setAttribute('monthly', $this->isMonthly($reseller));
        $reseller->setAttribute('effective_limit', $this->effectiveTestLimit($reseller));
        $reseller->setAttribute('effective_used', $this->effectiveUsed($reseller));
        $reseller->setAttribute('discount', $this->discountFor($reseller));
    }

    /**
     * The users behind a set of chat ids, keyed by chat id — the same join
     * the fork made, kept out of the query so the paginator stays simple.
     *
     * @param  Collection<int, string|int>  $chatIds
     * @return Collection<string, User>
     */
    private function usersByChatId(Collection $chatIds): Collection
    {
        $ids = $chatIds->map(static fn ($chatId): string => (string) $chatId)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('chat_id', $ids)->get()->keyBy('chat_id');
    }

    /**
     * A sortable Y-m-d H:i stamp from a value the schema left uncast.
     *
     * `reseller_requests.created_at` carries no datetime cast on the model
     * (the schema stores a raw string), so the view formats a prepared text
     * instead of calling ->format() on a string.
     */
    private function timeText(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tell the applicant what happened to their request. A messaging failure
     * is logged, never a reason to fail the decision itself.
     */
    private function notify(string $chatId, string $text): void
    {
        try {
            $this->telegram->sendMessage($chatId, $text, ['parse_mode' => 'HTML']);
        } catch (Throwable $e) {
            Log::warning('Could not notify the applicant about the reseller decision.', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ==================================================================
    // Bot side
    //
    // Port of the `reseller`, `reseller_apply` and repreqok/repreqno
    // branches of bot.php plus representativeStatusView() in the fork's
    // functions.php. The texts keep the fork's wording; the approval
    // buttons ride on the notification the applicant's own tap produces.
    // ==================================================================

    /**
     * The «نمایندگی» status screen, port of representativeStatusView():
     * an approved representative sees their summary, a pending applicant
     * sees the review notice, everyone else gets the requirement text with
     * the single `reseller_apply` button.
     *
     * @return array{text: string, reply_markup: string}
     */
    public function menuText(User $user): array
    {
        $chatId = (string) $user->chat_id;
        $back = [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']];

        $reseller = $this->resellerFor($chatId);

        if ($reseller !== null && $reseller->isActive()) {
            $discount = $this->discountFor($reseller);
            $suffix = $discount['type'] === 'percent' ? '٪' : ' تومان';

            $text = "🎖 <b>شما نماینده تاییدشده هستید.</b>\n\n"
                .'تخفیفهای شما بهصورت خودکار در خرید و تمدید اعمال میشوند';

            if ($discount['value'] > 0) {
                $text .= ":\n\n• تخفیف فعال: {$discount['value']}{$suffix}";
            } else {
                $text .= '.';
            }

            $text .= "\n\n💰 موجودی کیف پول: "
                .number_format((float) $reseller->balance).' تومان'
                ."\n🔖 کمیسیون: {$discount['value']}{$suffix}";

            return [
                'text' => $text,
                'reply_markup' => $this->keyboard([$back]),
            ];
        }

        $latest = $this->latestRequest($chatId);

        if ($latest !== null && $latest->status === 'PENDING') {
            return [
                'text' => "🎖 <b>درخواست نمایندگی شما در حال بررسی است.</b>\n\n"
                    .'پس از تایید توسط مدیریت، تخفیفهای نمایندگان بهصورت خودکار برای شما فعال خواهد شد.',
                'reply_markup' => $this->keyboard([$back]),
            ];
        }

        $minBalance = (float) $this->settings()['reseller_min_wallet_balance'];
        $currentBalance = $this->walletBalance($chatId);

        $text = "🎖 <b>درخواست نمایندگی</b>\n\n"
            .'برای ثبت درخواست نمایندگی، کیف پول شما باید حداقل <b>'
            .number_format($minBalance).' تومان</b> موجودی داشته باشد.\n'
            .'* این مبلغ فقط بررسی میشود و از کیف پول شما کسر نخواهد شد.\n\n'
            .'موجودی فعلی کیف پول شما: <b>'.number_format($currentBalance).' تومان</b>\n\n'
            .($latest !== null && $latest->status === 'REJECTED'
                ? 'درخواست قبلی شما رد شده بود؛ میتوانید دوباره درخواست دهید.\n\n'
                : '')
            .'برای ثبت درخواست، دکمه زیر را بزنید.';

        return [
            'text' => $text,
            'reply_markup' => $this->keyboard([
                [['text' => '📨 ارسال درخواست نمایندگی', 'callback_data' => 'reseller_apply']],
                $back,
            ]),
        ];
    }

    /**
     * Send a representative request, port of the `reseller_apply` branch.
     *
     * On success the message is replaced with the confirmation and every
     * administrator chat id receives the fork's notification carrying the
     * inline `repreqok_{id}` / `repreqno_{id}` decision buttons. A refused
     * request comes back as `answer` — the handler shows it as a callback
     * alert instead of editing, exactly as the fork did.
     *
     * @return array{text: string, reply_markup: string|null, answer: string|null}
     */
    public function apply(User $user): array
    {
        $chatId = (string) $user->chat_id;
        $balance = $this->walletBalance($chatId);

        if ($this->resellerFor($chatId)?->isActive()) {
            return $this->refused('شما هماکنون نماینده هستید.');
        }

        $existing = $this->latestRequest($chatId);

        if ($existing !== null && $existing->status === 'PENDING') {
            return $this->refused('درخواست قبلی شما هنوز در حال بررسی است.');
        }

        $minBalance = (float) $this->settings()['reseller_min_wallet_balance'];

        if ($balance < $minBalance) {
            return $this->refused(
                'حداقل موجودی کیف پول برای ارسال درخواست نمایندگی '.number_format($minBalance)
                .' تومان است.'.PHP_EOL
                .'موجودی فعلی شما: '.number_format($balance).' تومان.'.PHP_EOL.PHP_EOL
                .'(این مبلغ فقط بررسی میشود و از کیف پول شما کسر نخواهد شد.)'
            );
        }

        $request = ResellerRequest::query()->create([
            'chat_id' => $chatId,
            'user_id' => $user->id,
            'wallet_balance_at_request' => $balance,
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $name = (string) ($user->name ?? '');

        $adminText = "🔔 <b>درخواست نمایندگی جدید</b>\n\n"
            .'👤 آیدی کاربر: <code>'.self::e($chatId)."</code>\n"
            .($name !== '' ? 'نام: '.self::e($name)."\n" : '')
            .'💰 موجودی کیف پول: '.number_format($balance).' تومان';

        foreach ($this->adminIds() as $adminId) {
            $this->quietSend($adminId, $adminText, [
                'reply_markup' => $this->keyboard([
                    [
                        ['text' => '✅ تایید نمایندگی', 'callback_data' => 'repreqok_'.$request->id],
                        ['text' => '❌ رد درخواست', 'callback_data' => 'repreqno_'.$request->id],
                    ],
                ]),
            ]);
        }

        return [
            'text' => '📨 درخواست نمایندگی شما ثبت شد و برای بررسی به مدیریت ارسال گردید.',
            'reply_markup' => $this->keyboard([
                [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']],
            ]),
            'answer' => null,
        ];
    }

    /**
     * Approve from the bot's inline button (`repreqok_{id}`): administrators
     * only, then the panel's approveRequest() does the transactional work —
     * which already tells the applicant — and the answer text matches the
     * fork's callback wording.
     *
     * @return array{text: string, reply_markup: string|null}
     */
    public function approveRequestBot(int|string $requestId, string $adminChatId): array
    {
        if (! $this->guard()->isAdmin($adminChatId)) {
            return ['text' => '⛔ دسترسی غیرمجاز.', 'reply_markup' => null];
        }

        $request = ResellerRequest::query()->find((int) $requestId);

        $ok = $request !== null && $this->approveRequest($request, $adminChatId);

        return ['text' => $ok
            ? '✅ نمایندگی با موفقیت تایید شد.'
            : '❌ این درخواست یافت نشد یا قبلاً بررسی شده است.', 'reply_markup' => null];
    }

    /**
     * Reject from the bot's inline button (`repreqno_{id}`), the mirror of
     * {@see self::approveRequestBot()}.
     *
     * @return array{text: string, reply_markup: string|null}
     */
    public function rejectRequestBot(int|string $requestId, string $adminChatId): array
    {
        if (! $this->guard()->isAdmin($adminChatId)) {
            return ['text' => '⛔ دسترسی غیرمجاز.', 'reply_markup' => null];
        }

        $request = ResellerRequest::query()->find((int) $requestId);

        $ok = $request !== null && $this->rejectRequest($request, null, $adminChatId);

        return ['text' => $ok
            ? '❌ درخواست رد شد.'
            : '❌ این درخواست یافت نشد یا قبلاً بررسی شده است.', 'reply_markup' => null];
    }

    // ------------------------------------------------------------------
    // Bot-side internals
    // ------------------------------------------------------------------

    /** The representative row for a chat id, or null. */
    private function resellerFor(string $chatId): ?Reseller
    {
        return Reseller::query()->where('chat_id', $chatId)->first();
    }

    /** The latest request of any status for a chat id, or null. */
    private function latestRequest(string $chatId): ?ResellerRequest
    {
        return ResellerRequest::query()
            ->where('chat_id', $chatId)
            ->orderByDesc('id')
            ->first();
    }

    /** The wallet balance the fork read through wallet('get', ...). */
    private function walletBalance(string $chatId): float
    {
        $wallet = Wallet::query()->where('chat_id', $chatId)->first();

        return $wallet === null ? 0.0 : (float) $wallet->balance;
    }

    /** The administrator membership test, built from the resolved settings. */
    private function guard(): AdminGuard
    {
        return AdminGuard::fromSettings(app(PanelSettingsService::class));
    }

    /** Every configured administrator chat id (local config first). */
    private function adminIds(): array
    {
        $ids = array_values(array_filter(array_map(strval(...), (array) config('connectix_bot.admin_ids', []))));

        if ($ids !== []) {
            return $ids;
        }

        try {
            return app(PanelSettingsService::class)->adminIds();
        } catch (Throwable) {
            return [];
        }
    }

    /** The shape a refused apply() sends back (fork's answerCallbackQuery). */
    private function refused(string $message): array
    {
        return ['text' => $message, 'reply_markup' => null, 'answer' => $message];
    }

    /** Send without letting a Telegram failure break the flow. */
    private function quietSend(string $chatId, string $text, array $params = []): void
    {
        try {
            $this->telegram->sendMessage($chatId, $text, array_merge(['parse_mode' => 'HTML'], $params));
        } catch (Throwable $e) {
            Log::warning('A reseller notification could not be delivered.', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** The inline keyboard JSON the fork handed back to bot.php. */
    private function keyboard(array $rows): string
    {
        return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }

    /** Telegram-HTML escaping, the fork's EmojiService::escape(). */
    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
