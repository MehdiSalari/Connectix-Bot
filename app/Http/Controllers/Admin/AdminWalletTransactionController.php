<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The wallet ledger.
 *
 * Port of the legacy transactions/wallet_transactions.php list, showing every
 * ledger entry with its owner, type and status.
 */
class AdminWalletTransactionController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $transactions = WalletTransaction::query()
            ->with('wallet')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('chat_id', 'like', "%{$search}%");
            })
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.wallet-transactions.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'transactions' => $transactions,
            'search' => $search,
        ]);
    }
}
