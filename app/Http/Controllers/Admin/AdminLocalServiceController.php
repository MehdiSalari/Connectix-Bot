<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LocalPlan;
use App\Models\Panel;
use App\Services\Local\LocalUserService;
use App\Services\Panels\PanelNodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * کاربران و سرویس‌ها — users & services independent of Connectix.
 *
 * Port of the fork's localservices/index.php: hand-made users (Telegram chat
 * id), local services listed per panel, create-service fed by the local plan
 * list, and the attach form for an account that already exists on a panel.
 *
 * WAVE 3 SCOPE: everything here operates on local rows. The account
 * provisioning on the remote VPN panel (and the Telegram notification)
 * arrives with wave 4.
 */
class AdminLocalServiceController extends Controller
{
    public function __construct(
        private readonly LocalUserService $locals,
        private readonly PanelNodeService $panels,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'panel_id' => (int) $request->query('panel_id', 0),
            'user_id' => (int) $request->query('user_id', 0),
        ];

        $page = max(1, (int) $request->query('page', 1));
        [$rows, $total] = $this->locals->listServices($filters, $page, 25);

        return view('admin.localservices.index', [
            'active' => 'localservices',
            'title' => 'کاربران و سرویس‌ها',
            'types' => PanelNodeService::TYPES,
            'panels' => Panel::query()->orderBy('id')->get()->all(),
            'plans' => $this->plansForForm(),
            'filters' => $filters,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => 25,
            'users' => $filters['q'] !== '' ? $this->locals->searchUsers($filters['q']) : [],
        ]);
    }

    /**
     * The «افزودن کاربر» card. Creates the users row and opens the wallet.
     */
    public function createUser(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'chat_id' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:100'],
            'username' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->locals->createUser(
            $validated['chat_id'],
            $validated['name'],
            (string) ($validated['username'] ?? ''),
        );

        return back()->with($result['ok'] ? 'success' : 'error', $result['msg']);
    }

    /**
     * Block / unblock a user.
     */
    public function setBlocked(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'blocked' => ['required', 'boolean'],
        ]);

        $result = $this->locals->setBlocked((int) $validated['user_id'], (bool) $validated['blocked']);

        return back()->with($result['ok'] ? 'success' : 'error', $result['msg']);
    }

    /**
     * The user-picker search box.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        return response()->json([
            'ok' => true,
            'users' => $this->locals->searchUsers($q),
        ]);
    }

    /**
     * Create a service for a user from a local plan. Local rows only in
     * wave 3 (panel provisioning is wave 4).
     */
    public function createService(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'chat_id' => ['required', 'string', 'max:20'],
            'plan_id' => ['required', 'integer', 'min:1'],
            'charge_wallet' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $plan = LocalPlan::query()->find((int) $validated['plan_id']);

        if ($plan === null) {
            return back()->with('error', 'پلن پیدا نشد.');
        }

        $result = $this->locals->createService(
            $validated['chat_id'],
            $plan,
            [
                'charge_wallet' => (bool) ($validated['charge_wallet'] ?? false),
                'note' => (string) ($validated['note'] ?? ''),
            ],
        );

        return back()->with($result['ok'] ? 'success' : 'error', $result['msg']);
    }

    /**
     * Attach an account that already exists on a panel to a user.
     */
    public function attach(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'panel_id' => ['required', 'integer', 'min:1'],
            'remote_key' => ['required', 'string', 'max:80'],
            'chat_id' => ['required', 'string', 'max:20'],
        ]);

        $result = $this->locals->attach(
            (int) $validated['panel_id'],
            $validated['remote_key'],
            $validated['chat_id'],
        );

        return back()->with($result['ok'] ? 'success' : 'error', $result['msg']);
    }

    public function renew(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:100'],
            'plan_id' => ['required', 'integer', 'min:1'],
            'charge_wallet' => ['nullable', 'boolean'],
        ]);

        $plan = LocalPlan::query()->find((int) $validated['plan_id']);

        if ($plan === null) {
            return back()->with('error', 'پلن پیدا نشد.');
        }

        $result = $this->locals->renew(
            $validated['client_id'],
            $plan,
            (bool) ($validated['charge_wallet'] ?? false),
        );

        return back()->with($result['ok'] ? 'success' : 'error', $result['msg']);
    }

    public function setEnabled(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:100'],
            'enabled' => ['required', 'boolean'],
        ]);

        $result = $this->locals->setEnabled($validated['client_id'], (bool) $validated['enabled']);

        return back()->with($result['ok'] ? 'success' : 'error', $result['msg']);
    }

    public function resetTraffic(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:100'],
        ]);

        $result = $this->locals->resetTraffic($validated['client_id']);

        return back()->with($result['ok'] ? 'success' : 'error', $result['msg']);
    }

    /**
     * Soft-delete a service: the row is flagged is_deleted and stays, so
     * history keeps its references.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:100'],
        ]);

        $result = $this->locals->delete($validated['client_id']);

        return back()->with($result['ok'] ? 'success' : 'error', $result['msg']);
    }

    /**
     * The plan options the create-service form offers: active plans of
     * active panels, titled the Connectix way.
     *
     * @return array<int, array<string, mixed>>
     */
    private function plansForForm(): array
    {
        return collect($this->panels->listPlans())
            ->filter(fn (array $plan): bool => $plan['is_active'] && $plan['panel_active'])
            ->map(fn (array $plan): array => [
                'id' => (int) $plan['id'],
                'title' => $this->panels->planTitle($plan),
                'panel_id' => (int) $plan['panel_id'],
                'price' => (int) $plan['price'],
                'is_free' => (bool) $plan['is_free'],
            ])
            ->values()
            ->all();
    }
}
