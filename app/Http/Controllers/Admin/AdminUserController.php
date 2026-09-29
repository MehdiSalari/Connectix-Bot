<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\WalletOperation;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
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
        $user->load(['clientsByChatId', 'wallet', 'payments', 'walletTransactions']);

        return view('admin.users.show', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'user' => $user,
        ]);
    }

    /**
     * Create a wallet for a user that does not have one yet.
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
     */
    public function adjustWallet(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'chat_id' => ['required', 'string', 'exists:users,chat_id'],
            'operation' => ['required', Rule::in(['INCREASE', 'DECREASE'])],
            'amount' => ['required', 'integer', 'min:1'],
            'announce' => ['sometimes', 'boolean'],
        ]);

        $wallet = $this->wallets->adjust(
            (string) $data['chat_id'],
            $data['operation'] === 'INCREASE' ? WalletOperation::Increase : WalletOperation::Decrease,
            (int) $data['amount'],
            WalletTransactionType::DoneByAdmin,
            announce: $request->boolean('announce'),
        );

        if ($wallet === null) {
            return back()->with('error', 'کیف پول کاربر پیدا نشد. اول آن را بسازید.');
        }

        return back()->with('success', 'موجودی کیف پول به‌روزرسانی شد.');
    }
}
