<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\WalletOperation;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Panel\PanelSettingsService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The users list, profile and wallet administration.
 *
 * Port of the legacy users/index.php list and search, and of the profile page
 * with its wallet create and adjust actions. Role gating is an explicit
 * improvement over legacy, which only checked that an `admin_id` session
 * existed.
 */
class AdminUserController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->with('wallet')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('chat_id', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('telegram_id', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'users' => $users,
            'search' => $search,
        ]);
    }

    /**
     * The AJAX row list the legacy users/search.php returned.
     */
    public function search(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('term', ''));

        $users = User::query()
            ->select(['chat_id', 'telegram_id', 'name'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('chat_id', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('telegram_id', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['users' => collect($users)->map(fn ($user) => [
            'chat_id' => $user->chat_id,
            'telegram_id' => $user->telegram_id,
            'name' => $user->name,
        ])->values()]);
    }

    public function show(Request $request, User $user): View
    {
        // The full ledger is not loaded here any more. The profile page shows
        // the last few entries inline, and the "تاریخچه" button opens the rest
        // through walletHistory(), so a user with a thousand transactions does
        // not carry all of them into every profile view.
        $user->load(['clientsByChatId', 'wallet', 'payments']);

        $user->setRelation('walletTransactions', $this->wallets->history($user->chat_id, 8));

        return view('admin.users.show', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'user' => $user,
        ]);
    }

    /**
     * One user's wallet ledger, newest first, for the history modal.
     *
     * Read on demand for the same reason the details sheet is: the profile page
     * only previews the tail, so paging through the rest is one query per page
     * rather than a full ledger loaded on every profile view.
     */
    public function walletHistory(Request $request, string $chatId): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));

        $rows = $this->wallets->history($chatId, 15, $page);

        if ($rows->isEmpty() && $page > 1) {
            return response()->json(['error' => 'تراکنش بیشتری برای نمایش نیست.'], 404);
        }

        $balance = $this->wallets->find($chatId)?->balanceAmount();

        return response()->json([
            'balance' => $balance,
            'balance_text' => $balance === null ? null : number_format($balance),
            'page' => $rows->currentPage(),
            'pages' => $rows->lastPage(),
            'total' => $rows->total(),
            'transactions' => $rows->map(fn (WalletTransaction $tx): array => [
                'id' => $tx->getKey(),
                'amount_text' => number_format((int) $tx->amount),
                // The direction is a class on the view, not a colour decided in
                // the browser, so the ledger reads the same in the table, the
                // modal and anywhere else these rows are rendered.
                'direction' => $tx->operation === WalletOperation::Increase ? 'up' : 'down',
                'operation' => $tx->operation->label(),
                'type' => $tx->type->label(),
                'status' => $tx->status->labelForAdmin(),
                'status_tone' => $tx->status->isPending()
                    ? 'wait'
                    : ($tx->status->value === 'SUCCESS' ? 'ok' : 'no'),
                'created_at' => $tx->created_at?->format('Y-m-d H:i'),
            ])->values(),
        ]);
    }

    /**
     * Everything the users list row shows, for the details modal.
     *
     * The list has this data already, but the modal opens on demand from any
     * page of the list, so it reads the row itself rather than trusting a
     * snapshot a previous render left in the DOM. The list stays 20 cheap
     * queries; this is one query per modal opening.
     */
    public function details(Request $request, string $chatId): JsonResponse
    {
        $user = User::query()
            ->with(['wallet', 'clientsByChatId', 'payments' => fn ($query) => $query->latest('created_at')->limit(5)])
            ->where('chat_id', $chatId)
            ->first();

        if ($user === null) {
            return response()->json(['error' => 'کاربری با این شناسه پیدا نشد.'], 404);
        }

        return response()->json([
            'chat_id' => $user->chat_id,
            // لینک «پروفایل کامل» در پاورقی مودال: صفحه‌ی جزئیات کاربر، نه
            // جستجوی دوباره‌ی همان شناسه در لیست.
            'profile_url' => route('admin.users.show', $user),
            'name' => $user->name,
            'telegram' => $user->telegram_id,
            'avatar' => $user->avatar,
            'email' => $user->email,
            'phone' => $user->phone,
            'created_at' => $user->created_at?->format('Y-m-d H:i'),
            'used_test' => $user->hasUsedTest(),
            'wallet' => $user->wallet === null ? null : $user->wallet->balanceAmount(),
            'wallet_text' => $user->wallet === null ? null : number_format($user->wallet->balanceAmount()),
            'accounts' => $user->clientsByChatId->map(fn (Client $client): array => [
                'id' => $client->getKey(),
                'username' => $client->username,
                'label' => $client->username ?: (string) $client->getKey(),
                'status' => $client->statusBadge(),
            ])->values(),
            'orders' => $user->payments->map(fn (Payment $payment): array => [
                'order_number' => $payment->order_number,
                'price_text' => number_format($payment->priceAmount()),
                'status_label' => $payment->is_paid->label(),
                'tone' => $payment->is_paid->isDecided()
                    ? ($payment->is_paid->value === '1' ? 'ok' : 'no')
                    : 'wait',
            ])->values(),
        ]);
    }

    /**
     * Create a wallet for a user that does not have one yet.
     *
     * @deprecated No route reaches this any more. {@see self::adjustWallet()}
     *             opens the wallet it is about to credit or debit, so the two
     *             intents became one. Kept because the service method it wraps
     *             is still the only way a wallet row is created outside the bot.
     */
    public function createWallet(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'chat_id' => ['required', 'string', 'exists:users,chat_id'],
        ]);

        $wallet = Wallet::query()
            ->where('chat_id', $data['chat_id'])
            ->first();

        if ($wallet !== null) {
            return back()->with('error', 'کاربر از قبل کیف پول دارد.');
        }

        $this->wallets->create((string) $data['chat_id'], 0);

        return back()->with('success', 'کیف پول ساخته شد.');
    }

    /**
     * Adjust a user's wallet balance and announce it.
     *
     * Port of the wallet action on the legacy profile page: an admin increase
     * or decrease recorded as DONE_BY_ADMIN, with the optional Telegram
     * notification to the user.
     *
     * A user with no wallet yet gets one created here, at zero, and the
     * increase is applied on top of it. Creating a wallet used to be a
     * separate button on the same page, which meant a credit silently did
     * nothing until the admin noticed the row and pressed it first - two
     * steps for one intent, with a "wallet not found" error in between. The
     * create-wallet route and its button are gone; the first increase is the
     * intent that opens the wallet.
     *
     * A decrease is not folded in the same way. There is no wallet to open
     * for a debit - the money has to come from somewhere - and auto-creating
     * one at zero would drive the balance to a negative number and write a
     * ledger row for it. So an increase opens a missing wallet, and a
     * decrease still needs one that can afford it.
     */
    public function adjustWallet(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'chat_id' => ['required', 'string', 'exists:users,chat_id'],
            'operation' => ['required', Rule::in(['INCREASE', 'DECREASE'])],
            'amount' => ['required', 'integer', 'min:1'],
            'announce' => ['sometimes', 'boolean'],
        ]);

        $chatId = (string) $data['chat_id'];
        $increase = $data['operation'] === 'INCREASE';
        $amount = (int) $data['amount'];
        $created = false;

        if (! $increase && $this->wallets->find($chatId) === null) {
            return back()->with('error', 'برای کم کردن از موجودی، اول باید موجودی کیف پول افزایش پیدا کند.');
        }

        if ($increase && $this->wallets->find($chatId) === null) {
            $created = $this->wallets->create($chatId, 0) !== null;
        }

        $wallet = $this->wallets->adjust(
            $chatId,
            $increase ? WalletOperation::Increase : WalletOperation::Decrease,
            $amount,
            WalletTransactionType::DoneByAdmin,
            announce: $request->boolean('announce'),
        );

        if ($wallet === null) {
            return back()->with('error', 'کیف پول کاربر ساخته نشد؛ موجودی تغییر نکرد.');
        }

        if (! $increase && $wallet->balanceAmount() < 0) {
            // The service applies the arithmetic inside a transaction and has
            // already committed, so the overdraft is undone here rather than
            // refused up front - the two would need the same check in the
            // service to be correct for every caller, and the purchase flow
            // already guards its own debit before calling it.
            $this->wallets->adjust(
                $chatId,
                WalletOperation::Increase,
                $amount,
                WalletTransactionType::DoneByAdmin,
            );

            $available = number_format($wallet->balanceAmount() + $amount);

            return back()->with('error', "موجودی کیف پول این کاربر کافی نیست (موجودی فعلی: {$available} تومان).");
        }

        $balance = number_format($wallet->balanceAmount());

        return back()->with(
            'success',
            $created
                ? "کیف پول ساخته شد و موجودی آن به {$balance} تومان رسید."
                : "موجودی کیف پول به {$balance} تومان به‌روزرسانی شد."
        );
    }
}
