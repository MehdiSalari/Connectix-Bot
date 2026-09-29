<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
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
        $page = max(1, (int) $request->query('page', 1));

        $items = $this->payments->search($query, $page, 20);

        if ($status !== '' || $method !== '') {
            $items = $items->filter(function (Payment $payment) use ($status, $method): bool {
                $matchesStatus = match ($status) {
                    'pending' => $payment->is_paid->isPending(),
                    '0' => $payment->is_paid->value === '0',
                    '1' => $payment->is_paid->value === '1',
                    default => true,
                };

                $matchesMethod = $method === ''
                    || ($payment->method instanceof PaymentMethod && $payment->method->value === $method);

                return $matchesStatus && $matchesMethod;
            });
        }

        $total = $this->payments->countSearchResults($query);

        $pages = (int) ceil($total / 20);

        return view('admin.orders.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'payments' => $items,
            'page' => $page,
            'pages' => max(1, $pages),
            'total' => $total,
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
