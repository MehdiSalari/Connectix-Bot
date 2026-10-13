<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Panels\PanelNodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * پنل‌های VPN و پلن‌ها — Marzban / 3x-ui / Remnawave / Hiddify.
 *
 * Port of the fork's panels/index.php: the panel table with its add/edit
 * forms, the «تست اتصال» button (driver testConnection, Http::fake in
 * tests), and the per-panel plan list with its own add/edit form.
 *
 * The encrypted secret never reaches a view: the edit form shows a has_secret
 * note and an empty password field, exactly like the fork's
 * «خالی بگذارید تا تغییر نکند».
 */
class AdminPanelNodeController extends Controller
{
    public function __construct(
        private readonly PanelNodeService $panels,
    ) {}

    public function index(Request $request): View
    {
        $editPanelId = (int) $request->query('edit_panel', 0);
        $editPlanId = (int) $request->query('edit_plan', 0);
        $planPanelId = (int) $request->query('plan_panel', 0);

        // present() keeps the type-specific settings nested under `extra`;
        // the page reads flat keys (verify_ssl, inbound_id, ...), so lift
        // them here with the same defaults the service saves with.
        $flatten = static fn (array $panel): array => ($panel['extra'] ?? []) + [
            'verify_ssl' => true,
            'inbound_id' => '',
            'flow' => '',
            'sub_base' => '',
            'otp' => '',
            'squads' => '',
            'proxies_json' => '',
            'inbounds_json' => '',
        ] + $panel;

        return view('admin.panels.index', [
            'active' => 'panels',
            'title' => 'پنل‌های VPN و پلن‌ها',
            'types' => PanelNodeService::TYPES,
            'groups' => PanelNodeService::GROUPS,
            'panels' => array_map($flatten, $this->panels->listPanels()),
            'plans' => $this->panels->listPlans(),
            'editPanel' => $editPanelId > 0 ? $flatten($this->panels->getPanel($editPanelId) ?? []) : null,
            'editPlan' => $editPlanId > 0 ? $this->panels->getPlan($editPlanId) : null,
            'planPanelId' => $planPanelId,
            // The Connectix-style title («(2x) 50GB-30D Gold») for the plan rows.
            'planTitle' => fn (array $plan): string => $this->panels->planTitle($plan),
        ]);
    }

    /**
     * Add or edit a panel (the same form serves both; id=0 means create).
     */
    public function savePanel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:0'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:marzban,xui,remnawave,hiddify'],
            'base_url' => ['required', 'string', 'max:500'],
            'username' => ['nullable', 'string', 'max:190'],
            'secret' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'verify_ssl' => ['nullable', 'boolean'],
            'inbound_id' => ['nullable', 'string', 'max:20'],
            'flow' => ['nullable', 'string', 'max:100'],
            'sub_base' => ['nullable', 'string', 'max:500'],
            'otp' => ['nullable', 'string', 'max:100'],
            'squads' => ['nullable', 'string', 'max:1000'],
            'proxies_json' => ['nullable', 'string', 'max:2000'],
            'inbounds_json' => ['nullable', 'string', 'max:2000'],
        ]);

        $id = (int) ($validated['id'] ?? 0) ?: null;
        $error = $this->panels->savePanel($id, $validated);

        if ($error !== null) {
            return back()->withInput()->with('error', $error);
        }

        return redirect()
            ->route('admin.panels.index')
            ->with('success', 'پنل ذخیره شد. حالا «تست اتصال» را بزنید.');
    }

    public function deletePanel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->panels->deletePanel((int) $validated['id']);

        return back()->with($result['ok'] ? 'success' : 'error', $result['msg']);
    }

    public function testPanel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->panels->testPanel((int) $validated['id']);

        return back()->with($result['ok'] ? 'success' : 'error', $result['msg']);
    }

    /**
     * Add or edit a local plan (the per-panel price tag).
     */
    public function savePlan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:0'],
            'name' => ['nullable', 'string', 'max:60'],
            // numeric, not string: the form posts digits but tests and the
            // service pass integers; the service sanitises either form.
            'price' => ['nullable', 'numeric', 'min:0'],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'traffic_gb' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'devices' => ['required', 'integer', 'min:1', 'max:20'],
            'panel_id' => ['required', 'integer', 'min:1'],
            'group_name' => ['required', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_free' => ['nullable', 'boolean'],
        ]);

        $id = (int) ($validated['id'] ?? 0) ?: null;
        $error = $this->panels->savePlan($id, $validated);

        if ($error !== null) {
            return back()->withInput()->with('error', $error);
        }

        return redirect()
            ->route('admin.panels.index')
            ->with('success', 'پلن ذخیره شد.');
    }

    public function deletePlan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $this->panels->deletePlan((int) $validated['id']);

        return back()->with('success', 'پلن حذف شد. (اکانت‌های قبلاً فروخته‌شده دست‌نخورده می‌مانند.)');
    }
}
