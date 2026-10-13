<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reseller;
use App\Models\ResellerRequest;
use App\Services\Panel\PanelSettingsService;
use App\Services\Resellers\ResellerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Representative (نمایندگی) management.
 *
 * Port of the fork's reseller/index.php: the pending requests to approve or
 * reject, the representatives with their free-test quota and discount, and
 * the default settings the quota and discount fall back to.
 *
 * Reading is open to both roles; every mutation is admin-only, the same
 * boundary the rest of the panel draws.
 */
class AdminResellerController extends Controller
{
    public function __construct(
        private readonly ResellerService $resellers,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        return view('admin.resellers.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'resellers' => $this->resellers->listResellers($search),
            'requests' => $this->resellers->openRequests(),
            'stats' => $this->resellers->stats(),
            'settings' => $this->resellers->settings(),
            'search' => $search,
        ]);
    }

    /**
     * Save the defaults new representatives inherit.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reseller_min_wallet_balance' => ['required', 'numeric', 'min:0'],
            'reseller_default_test_limit' => ['required', 'integer', 'min:0'],
            'reseller_default_test_unlimited' => ['sometimes', 'boolean'],
            'reseller_monthly_quota' => ['sometimes', 'boolean'],
            'reseller_default_discount_type' => ['required', Rule::in(ResellerService::DISCOUNT_TYPES)],
            'reseller_default_discount_value' => ['required', 'numeric', 'min:0'],
        ]);

        $this->resellers->saveSettings($data);

        return back()->with('success', 'تنظیمات تخفیف نمایندگان ذخیره شد.');
    }

    /**
     * Set one representative's free-test quota (and optionally clear their
     * used counter in the same action, the fork's combined row form).
     */
    public function quota(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reseller' => ['required', 'integer', 'exists:resellers,id'],
            'test_limit' => ['nullable', 'integer', 'min:0'],
            'unlimited' => ['sometimes', 'boolean'],
            'monthly' => ['sometimes', 'boolean'],
            'reset_used' => ['sometimes', 'boolean'],
        ]);

        $reseller = Reseller::query()->findOrFail((int) $data['reseller']);

        $limit = ! empty($data['unlimited'])
            ? -1
            : (array_key_exists('test_limit', $data) && $data['test_limit'] !== null
                ? (int) $data['test_limit']
                : null);

        $this->resellers->setTestQuota($reseller, $limit, (bool) ($data['monthly'] ?? false));

        if (! empty($data['reset_used'])) {
            $this->resellers->resetTestUsed($reseller);
        }

        return back()->with('success', 'سهمیهٔ تست رایگان نماینده ذخیره شد.');
    }

    /**
     * Clear one representative's used-test counter.
     */
    public function resetUsed(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reseller' => ['required', 'integer', 'exists:resellers,id'],
        ]);

        $this->resellers->resetTestUsed(
            Reseller::query()->findOrFail((int) $data['reseller']),
        );

        return back()->with('success', 'مصرف تست رایگان این نماینده صفر شد.');
    }

    /**
     * Save one representative's discount override.
     */
    public function discount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reseller' => ['required', 'integer', 'exists:resellers,id'],
            'type' => ['required', Rule::in(ResellerService::DISCOUNT_TYPES)],
            'value' => ['required', 'numeric', 'min:0'],
        ]);

        $this->resellers->setDiscount(
            Reseller::query()->findOrFail((int) $data['reseller']),
            (string) $data['type'],
            (float) $data['value'],
        );

        return back()->with('success', 'تخفیف نماینده ذخیره شد.');
    }

    /**
     * Approve or reject one pending request.
     */
    public function decide(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'integer', 'exists:reseller_requests,id'],
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'admin_note' => ['nullable', 'string', 'max:500'],
        ]);

        $resellerRequest = ResellerRequest::query()->findOrFail((int) $data['request_id']);
        $processedBy = (string) ($request->user('admin')?->email ?? 'ادمین');

        if ($data['action'] === 'approve') {
            $done = $this->resellers->approveRequest($resellerRequest, $processedBy);

            return back()->with(
                $done ? 'success' : 'warning',
                $done ? 'درخواست تایید و نمایندگی فعال شد.' : 'خطا در تایید درخواست.',
            );
        }

        $done = $this->resellers->rejectRequest($resellerRequest, $data['admin_note'] ?? null, $processedBy);

        return back()->with(
            $done ? 'success' : 'warning',
            $done ? 'درخواست رد شد.' : 'خطا در رد درخواست.',
        );
    }
}
