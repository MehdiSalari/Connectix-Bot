<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Plan\PlanService;
use App\Services\Shop\FlashSaleAdminService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * حراج زمان‌دار — Admin controller
 */
class AdminFlashSaleController extends Controller
{
    public function __construct(
        private readonly FlashSaleAdminService $flashSaleService,
        private readonly PlanService $plans,
    ) {}

    public function index(Request $request): View
    {
        $actionMsg = null;

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'action' => ['required', 'in:create,toggle,delete'],
                'plan_id' => ['nullable', 'string'],
                'plan_title' => ['nullable', 'string'],
                'discount_type' => ['nullable', 'in:percent,fixed'],
                'discount_value' => ['nullable', 'integer', 'min:1'],
                'starts_at' => ['nullable', 'string'],
                'ends_at' => ['nullable', 'string'],
                'id' => ['nullable', 'integer'],
                'to' => ['nullable', 'boolean'],
            ]);

            $action = $validated['action'];

            try {
                if ($action === 'create') {
                    $startsAt = FlashSaleAdminService::parseInputTime($validated['starts_at']);
                    $endsAt = FlashSaleAdminService::parseInputTime($validated['ends_at']);
                    $planId = trim((string) ($validated['plan_id'] ?? ''));
                    $plan = $planId === '' ? null : $this->plans->findById($planId);

                    if (! $startsAt || ! $endsAt) {
                        $actionMsg = ['type' => 'error', 'text' => 'زمان شروع و پایان معتبر نیست.'];
                    } elseif ($plan === null) {
                        $actionMsg = ['type' => 'error', 'text' => 'پلن انتخابشده در کاتالوگ پیدا نشد؛ لیست را تازه کنید.'];
                    } elseif ($endsAt <= $startsAt) {
                        $actionMsg = ['type' => 'error', 'text' => 'زمان پایان باید بعد از زمان شروع باشد.'];
                    } else {
                        $this->flashSaleService->create([
                            'plan_id' => (string) ($plan['id'] ?? $planId),
                            'plan_title' => (string) ($plan['title'] ?? ''),
                            'discount_type' => $validated['discount_type'] ?? 'percent',
                            'discount_value' => (int) ($validated['discount_value'] ?? 0),
                            'starts_at' => $startsAt,
                            'ends_at' => $endsAt,
                        ]);
                        $actionMsg = ['type' => 'success', 'text' => 'حراج ثبت شد.'];
                    }
                } elseif ($action === 'toggle') {
                    $this->flashSaleService->setActive((int) ($validated['id'] ?? 0), ! empty($validated['to']));
                    $actionMsg = ['type' => 'success', 'text' => 'وضعیت حراج تغییر کرد.'];
                } elseif ($action === 'delete') {
                    $this->flashSaleService->delete((int) ($validated['id'] ?? 0));
                    $actionMsg = ['type' => 'success', 'text' => 'حراج حذف شد.'];
                }
            } catch (\InvalidArgumentException $e) {
                $actionMsg = ['type' => 'error', 'text' => $e->getMessage()];
            } catch (\Throwable $e) {
                $actionMsg = ['type' => 'error', 'text' => 'خطا در پردازش: '.$e->getMessage()];
            }
        }

        $sales = $this->flashSaleService->all();
        $now = $this->flashSaleService->nowTehran();

        return view('admin.flash-sales.index', [
            'active' => 'flash-sales',
            'title' => 'حراج زماندار',
            'sales' => $sales,
            'now' => $now,
            'plans' => $this->plans->sellablePlans(),
            'actionMsg' => $actionMsg,
        ]);
    }
}
