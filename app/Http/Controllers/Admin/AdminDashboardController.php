<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Panel\PanelSettingsService;
use App\Services\Telegram\TelegramProfileService;
use App\Support\PersianCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * The landing page behind the login, rebuilt on the Airoxnet dashboard skin.
 *
 * Every figure is real data read from the legacy tables; the revenue rules
 * follow the v22-v24 fixes of the legacy panel: comma-formatted prices are
 * stripped before summing (a plain CAST reads "25,000" as 25), the month is
 * the Jalali month compared against the same number of days of the previous
 * Jalali month (a half-finished month must not meet a whole one), revenue
 * sits on the payment's approval time when that column exists (falling back
 * to created_at), and a percentage against an empty previous period prints
 * "—" instead of a misleading "+100%".
 *
 * All day boundaries are Tehran calendar days — the install runs PHP and the
 * database on the same Tehran clock, so the wall-clock strings compare
 * correctly against the stored created_at stamps.
 */
class AdminDashboardController extends Controller
{
    /**
     * Whether `payments.approved_at` exists. Resolved once and kept for the
     * process lifetime: the migration adding it is a one-way fact per
     * install, and probing the schema on every dashboard hit would spend a
     * query re-learning an answer that cannot change mid-install.
     */
    private static ?bool $hasApprovedAt = null;

    /**
     * The legacy markers of a settled payment, the same list the welcome
     * credit guard treats as a real purchase (docs/legacy-audit.md §payment
     * parity): '1' / '2' are the documented approvals, 'OK' / 'APPROVED'
     * appear in older rows.
     */
    private const APPROVED = ['1', '2', 'OK', 'APPROVED'];

    /** The documented refusals; anything else (NULL included) is pending. */
    private const REJECTED = ['0', 'REJECTED'];

    public function __invoke(Request $request): View
    {
        $admin = $request->user('admin');

        $now = Carbon::now('Asia/Tehran');
        $today = $now->toDateString();
        $tomorrow = $now->copy()->addDay()->toDateString();
        $windowStart = $now->copy()->subDays(6)->toDateString();

        // The chart window the page asked for (۷ / ۳۰ / ۹۰ روز اخیر). Anything
        // else — absent, junk, out of the fork's own clamp — reads as seven,
        // so the default page keeps the seven-slot series the panel always
        // showed and the pinned chart tests pin down.
        $range = (int) $request->input('range', 7);
        if (! in_array($range, [7, 30, 90], true)) {
            $range = 7;
        }
        $seriesWindowStart = $now->copy()->subDays($range - 1)->toDateString();

        // Jalali month arithmetic: the previous month and the current one,
        // both as [start, end) Tehran windows, plus how far into the current
        // month today sits — the slice the previous month is compared on.
        [$prevMonth, $thisMonth] = PersianCalendar::jalaliMonthsWindow($now, 2);
        $elapsed = PersianCalendar::toJalali(
            (int) $now->format('Y'),
            (int) $now->format('n'),
            (int) $now->format('j'),
        )['day'];
        $prevCompareEnd = $prevMonth['start']->copy()->addDays($elapsed)->min($thisMonth['start']);

        $revDate = self::revenueDateExpression();

        // Comma-formatted prices ("25,000") sum through REPLACE: the raw
        // string cast would stop at the separator and understate revenue.
        $sum = static fn (string $from, ?string $to): int => (int) Payment::query()
            ->selectRaw("COALESCE(SUM(REPLACE(price, ',', '')), 0) as total")
            ->when($from !== '', static fn ($q) => $q->whereRaw("DATE({$revDate}) >= ?", [$from]))
            ->when($to !== null, static fn ($q) => $q->whereRaw("DATE({$revDate}) < ?", [$to]))
            ->value('total');

        $revenueToday = $sum($today, $tomorrow);
        $revenueYesterday = $sum($now->copy()->subDay()->toDateString(), $today);
        $revenueMonth = $sum($thisMonth['start']->toDateString(), $thisMonth['end']->toDateString());
        $revenuePrevPeriod = $sum($prevMonth['start']->toDateString(), $prevCompareEnd->toDateString());
        $revenueTotal = $sum('', null);

        // New users, this 7-day window and the one before it, so the users
        // card can show a percentage the way the revenue cards do.
        $usersWindow = static fn (string $from, string $to): int => User::query()
            ->whereRaw('DATE(created_at) >= ?', [$from])
            ->whereRaw('DATE(created_at) < ?', [$to])
            ->count();
        $newUsers7d = $usersWindow($windowStart, $tomorrow);
        $newUsersPrev7d = $usersWindow(
            $now->copy()->subDays(13)->toDateString(),
            $windowStart,
        );

        $monthNames = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

        // One slot per Tehran calendar day of the chosen window, oldest first.
        // The query GROUPs BY the Tehran date; the map only fills days that
        // had rows, leaving the rest at a flat zero. The slot count follows
        // the range selector (۷ / ۳۰ / ۹۰), so a wider window re-queries the
        // same series over more days rather than slicing seven days thinner.
        $series = static function (string $sql, array $bindings) use ($now, $monthNames, $range): array {
            $byDay = [];

            foreach (DB::select($sql, $bindings) as $row) {
                $byDay[$row->d] = (int) $row->v;
            }

            $out = [];

            for ($i = $range - 1; $i >= 0; $i--) {
                $day = $now->copy()->subDays($i);
                $j = PersianCalendar::toJalali((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'));

                $out[] = [
                    'd' => $day->toDateString(),
                    'label' => PersianCalendar::toPersianDigits($j['day']).' '.$monthNames[$j['month'] - 1],
                    'value' => $byDay[$day->toDateString()] ?? 0,
                ];
            }

            return $out;
        };

        $seriesRevenue = $series(
            "SELECT DATE({$revDate}) d, SUM(REPLACE(price, ',', '')) v FROM payments "
                ."WHERE DATE({$revDate}) >= ? AND DATE({$revDate}) < ? GROUP BY DATE({$revDate})",
            [$seriesWindowStart, $tomorrow],
        );
        $seriesUsers = $series(
            'SELECT DATE(created_at) d, COUNT(*) v FROM users '
                .'WHERE DATE(created_at) >= ? AND DATE(created_at) < ? GROUP BY DATE(created_at)',
            [$seriesWindowStart, $tomorrow],
        );

        /*
         * The KPI row the fork's dashboard prints: total users with the
         * blocked count riding along, distinct paying customers (at least one
         * successful purchase — loyalty redemptions are gifts, not purchases,
         * so their rows are excluded the same way the welcome-credit guard
         * excludes them), open tickets, active resellers and the parsed wallet
         * pool. Every table is a feature-fork table that may not exist on an
         * install that stopped at the legacy schema, so each count is a
         * guarded read that answers zero rather than breaking the page.
         */
        $buyersTotal = Payment::query()
            ->whereIn('is_paid', self::APPROVED)
            ->where(fn ($q) => $q
                ->whereNull('method')
                ->orWhere('method', '')
                ->orWhere('method', '!=', 'loyalty'))
            ->distinct()
            ->count('chat_id');

        $blockedUsers = (int) User::query()->where('is_blocked', 1)->count();

        $openTickets = 0;
        if (class_exists(Ticket::class)) {
            try {
                $openTickets = Ticket::query()->where('status', 'OPEN')->count();
            } catch (\Throwable) {
                $openTickets = 0;
            }
        }

        $activeResellers = 0;
        if (class_exists(Reseller::class)) {
            try {
                $activeResellers = Reseller::query()->where('status', 'ACTIVE')->count();
            } catch (\Throwable) {
                $activeResellers = 0;
            }
        }

        // The balance column is VARCHAR; a plain SUM reads "25,000" as 25, so
        // the separators are stripped before adding, like the price column.
        $walletBalanceTotal = (int) DB::table('wallets')
            ->selectRaw("COALESCE(SUM(REPLACE(REPLACE(balance, ',', ''), ' ', '')), 0) as total")
            ->value('total');

        // Transaction status split for the doughnut: the documented approval
        // and refusal markers, and everything else (NULL pending rows
        // included) counted as awaiting a decision — the fork's own bucketing.
        $successCount = Payment::query()->whereIn('is_paid', self::APPROVED)->count();
        $rejectedCount = Payment::query()->whereIn('is_paid', self::REJECTED)->count();
        $totalPayments = Payment::query()->count();

        // The most used coupon codes inside the chosen window: empty strings
        // and NULLs are "no coupon", not a coupon, so they never row up.
        $topCoupons = Payment::query()
            ->selectRaw("coupon, COUNT(*) as uses, SUM(REPLACE(REPLACE(REPLACE(price, ',', ''), ' ', ''), '٬', '')) as revenue")
            ->whereNotNull('coupon')
            ->where('coupon', '!=', '')
            ->whereIn('is_paid', self::APPROVED)
            ->where(fn ($q) => $q
                ->whereNull('method')
                ->orWhere('method', '')
                ->orWhere('method', '!=', 'loyalty'))
            ->whereRaw("DATE({$revDate}) >= ? AND DATE({$revDate}) < ?", [$seriesWindowStart, $tomorrow])
            ->groupBy('coupon')
            ->orderByDesc('uses')
            ->limit(5)
            ->get()
            ->map(static fn ($row): array => [
                'coupon' => (string) $row->coupon,
                'uses' => (int) $row->uses,
                'revenue' => (int) $row->revenue,
            ])
            ->all();

        // Recent orders flattened to plain rows: `method` is read raw, so a
        // legacy value outside the enum ("loyalty") cannot throw mid-render.
        $recentOrders = Payment::query()
            ->with('user')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(static function (Payment $payment): array {
                $raw = (string) $payment->getRawOriginal('method');

                // The enum covers card/wallet; any other value legacy
                // recorded is the points-reward purchase, which never
                // carries an amount.
                $label = match ($raw) {
                    PaymentMethod::Card->value => PaymentMethod::Card->label(),
                    PaymentMethod::Wallet->value => PaymentMethod::Wallet->label(),
                    '' => '—',
                    default => 'جایزه امتیاز',
                };

                $paid = $payment->is_paid;

                return [
                    'order_number' => (string) ($payment->order_number ?? ''),
                    'user_name' => (string) ($payment->user?->name ?? ''),
                    'user_chat_id' => (string) ($payment->chat_id ?? ''),
                    'amount' => $payment->priceAmount(),
                    'method' => $raw,
                    'method_label' => $label,
                    'status_label' => $paid->label(),
                    'status_tone' => $paid->isDecided() ? ($paid->value === '1' ? 'ok' : 'no') : 'wait',
                    'created_at' => $payment->created_at?->format('Y-m-d H:i'),
                ];
            })
            ->all();

        return view('admin.dashboard', [
            'appName' => app(PanelSettingsService::class)->appName(),
            // عکس ربات از صفحه‌ی عمومی t.me خود ربات می‌آید (مثل عکس کاربران).
            'bot' => app(TelegramProfileService::class)->botProfile(),
            'admin' => $admin,
            'stats' => [
                'users_total' => User::query()->count(),
                'blocked_users' => $blockedUsers,
                'buyers_total' => $buyersTotal,
                'new_users_today' => $usersWindow($today, $tomorrow),
                'new_users_7d' => $newUsers7d,
                'new_users_prev_7d' => $newUsersPrev7d,
                'new_users_delta' => self::deltaPct($newUsers7d, $newUsersPrev7d),
                'revenue_today' => $revenueToday,
                'revenue_yesterday' => $revenueYesterday,
                'revenue_today_delta' => self::deltaPct($revenueToday, $revenueYesterday),
                'revenue_month' => $revenueMonth,
                'revenue_prev_period' => $revenuePrevPeriod,
                'revenue_month_delta' => self::deltaPct($revenueMonth, $revenuePrevPeriod),
                'revenue_total' => $revenueTotal,
                'orders_today' => Payment::query()->whereRaw('DATE(created_at) = ?', [$today])->count(),
                'pending' => Payment::query()->whereNull('is_paid')->count(),
                'open_tickets' => $openTickets,
                'active_resellers' => $activeResellers,
                'wallet_total' => (int) DB::table('wallets')->selectRaw('COALESCE(SUM(balance), 0) as total')->value('total'),
                'wallet_balance_total' => $walletBalanceTotal,
                'status_counts' => [
                    'success' => $successCount,
                    'rejected' => $rejectedCount,
                    'pending' => max(0, $totalPayments - $successCount - $rejectedCount),
                ],
                'top_coupons' => $topCoupons,
                'series_revenue' => $seriesRevenue,
                'series_users' => $seriesUsers,
                'range' => $range,
                'recent_orders' => $recentOrders,
            ],
            'recent_users' => User::query()
                ->orderByDesc('created_at')
                ->limit(8)
                ->get(),
        ]);
    }

    /**
     * The stamp revenue sits on: the approval time once the migration lands,
     * until then the order time. A card payment approved the next day should
     * count on the day it was approved, not the day it was submitted.
     */
    private static function revenueDateExpression(): string
    {
        if (self::$hasApprovedAt === null) {
            self::$hasApprovedAt = Schema::hasColumn('payments', 'approved_at');
        }

        return self::$hasApprovedAt
            ? 'COALESCE(approved_at, created_at)'
            : 'created_at';
    }

    /**
     * Percentage change of the previous period, or null when the previous
     * period was zero — "+100%" over an empty baseline would be a lie.
     */
    private static function deltaPct(int $current, int $previous): ?float
    {
        return $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : null;
    }
}
