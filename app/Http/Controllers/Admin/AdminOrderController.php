<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Panel\PanelSettingsService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The order list and payment approval.
 *
 * Port of the legacy transactions/transactions.php, whose rows are the
 * `payments` table and whose search uses the same columns. Accepting or
 * rejecting an order writes the same `is_paid` value the Telegram approval
 * flow writes, so the panel and the callback stay consistent.
 *
 * The list is a real paginator. It used to fetch a page of twenty rows and
 * then drop the ones that did not match the status or method filter in PHP,
 * so a filtered page could show three rows of twenty and the hand-written
 * pager it printed counted the unfiltered rows - offering pages that were
 * empty once a filter was applied. Both filters are SQL now, and the list
 * uses the same pager view as the users and ledger lists.
 */
class AdminOrderController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): View
    {
        $query = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $method = (string) $request->query('method', '');

        $orders = $this->payments->paginate($query, $status, $method, 20);

        return view('admin.orders.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'payments' => $orders,
            'total' => $orders->total(),
            'search' => $query,
            'status' => $status,
            'method' => $method,
        ]);
    }

    /**
     * Accept or reject an order still awaiting a decision.
     */
    public function decide(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['accept', 'reject'])],
        ]);

        $changed = $data['action'] === 'accept'
            ? $this->payments->markPaid($payment->id)
            : $this->payments->markRejected($payment->id);

        $label = PaymentStatus::from($data['action'] === 'accept' ? '1' : '0')->label();

        if (! $changed) {
            return back()->with('error', 'این سفارش از قبل تعیین تکلیف شده است.');
        }

        return back()->with('success', "سفارش {$payment->order_number} {$label} شد.");
    }
}
