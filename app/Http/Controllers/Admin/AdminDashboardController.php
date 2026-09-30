<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Panel\PanelSettingsService;
use App\Services\Telegram\TelegramProfileService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The landing page behind the login.
 *
 * Replaces the Phase 12 placeholder with the overview the legacy root
 * index.php rendered: counters for users, orders and wallet balances, plus the
 * most recent activity.
 */
class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $admin = $request->user('admin');

        $today = Payment::query()
            ->whereDate('created_at', today())
            ->get();

        $todayCount = $today->count();
        $todaySum = $today->reduce(
            static fn (int $carry, Payment $payment): int => $carry + $payment->priceAmount(),
            0
        );
        $pendingPayments = Payment::query()->whereNull('is_paid')->count();
        $walletBalance = Wallet::query()->get()->reduce(
            static fn (int $carry, Wallet $wallet): int => $carry + $wallet->balanceAmount(),
            0
        );

        return view('admin.dashboard', [
            'appName' => app(PanelSettingsService::class)->appName(),
            // عکس ربات از صفحه‌ی عمومی t.me خود ربات می‌آید (مثل عکس کاربران).
            'bot' => app(TelegramProfileService::class)->botProfile(),
            'admin' => $admin,
            'stats' => [
                'users' => User::query()->count(),
                'today_payments' => $todayCount,
                'today_sum' => $todaySum,
                'pending_payments' => $pendingPayments,
                'wallet_balance' => $walletBalance,
            ],
            'recent_users' => User::query()
                ->orderByDesc('created_at')
                ->limit(8)
                ->get(),
            'recent_payments' => Payment::query()
                ->with('user')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get(),
        ]);
    }
}
