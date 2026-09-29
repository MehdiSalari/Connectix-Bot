<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsPayment;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inbound bank SMS deposits.
 *
 * Port of the legacy transactions/sms_payments.php list.
 */
class AdminSmsPaymentController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $deposits = SmsPayment::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('message', 'like', "%{$search}%")
                    ->orWhere('bank', 'like', "%{$search}%")
                    ->orWhere('payment_id', 'like', "%{$search}%");
            })
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.sms-payments.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'deposits' => $deposits,
            'search' => $search,
        ]);
    }
}
