<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Coupons\LocalCouponService;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * کد تخفیف — the local discount codes.
 *
 * Port of the local-coupons half of the fork's coupons/index.php (the remote
 * Connectix coupon cards need the seller panel, which is a later wave). The
 * codes live in panel_settings as a JSON list — see LocalCouponService — and
 * the page keeps the fork's shape: an add/edit form over the table, an inline
 * active toggle and a confirm-guarded delete.
 */
class AdminCouponController extends Controller
{
    public function __construct(
        private readonly LocalCouponService $coupons,
    ) {}

    public function index(Request $request): View
    {
        $editCode = trim((string) $request->query('edit', ''));

        return view('admin.coupons.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'coupons' => $this->coupons->all(),
            'editing' => $editCode !== '' ? $this->coupons->find($editCode) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]{3,40}$/'],
            'kind' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'integer', 'min:1'],
            'max_uses' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $fields = [
            'code' => $validated['code'],
            'kind' => $validated['kind'],
            'value' => (int) $validated['value'],
            'max_uses' => (int) ($validated['max_uses'] ?? 0),
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ];

        $original = trim((string) $request->input('original_code', ''));

        try {
            if ($original !== '') {
                $this->coupons->update($original, $fields);
            } else {
                $this->coupons->add($fields);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('admin.coupons.index')
            ->with('success', 'کد تخفیف محلی ذخیره شد.');
    }

    public function toggle(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'on' => ['required', 'boolean'],
        ]);

        $this->coupons->setActive($validated['code'], (bool) $validated['on']);

        return redirect()
            ->route('admin.coupons.index')
            ->with('success', 'وضعیت کد تخفیف تغییر کرد.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
        ]);

        $this->coupons->delete($validated['code']);

        return redirect()
            ->route('admin.coupons.index')
            ->with('success', 'کد تخفیف محلی حذف شد.');
    }
}
