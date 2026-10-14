<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyReward;
use App\Models\User;
use App\Services\Loyalty\LoyaltyAdminService;
use App\Services\Plan\PlanService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * سیستم امتیازدهی — Admin controller
 */
class AdminLoyaltyController extends Controller
{
    public function __construct(
        private readonly LoyaltyAdminService $loyaltyService,
        private readonly PlanService $planService,
    ) {}

    public function index(Request $request): View
    {
        $actionMsg = null;

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'action' => ['required', 'in:save_settings,save_reward,delete_reward,adjust,generate_codes,manual_paid'],
                // settings
                'enabled' => ['nullable', 'boolean'],
                'rate_toman' => ['nullable', 'integer', 'min:1'],
                'first_bonus' => ['nullable', 'integer', 'min:0'],
                'renew_bonus' => ['nullable', 'integer', 'min:0'],
                'daily_enabled' => ['nullable', 'boolean'],
                'daily_points' => ['nullable', 'integer', 'min:0'],
                'daily_month_cap' => ['nullable', 'integer', 'min:0'],
                'expiry_months' => ['nullable', 'integer', 'min:0'],
                'exclude_resellers' => ['nullable', 'boolean'],
                'notify_bot' => ['nullable', 'boolean'],
                'webapp_label' => ['nullable', 'string', 'max:60'],
                'code_valid_days' => ['nullable', 'integer', 'min:1', 'max:365'],
                'admin_contact' => ['nullable', 'string', 'max:64'],
                // reward
                'reward_id' => ['nullable', 'integer'],
                'title' => ['nullable', 'string', 'max:120'],
                'description' => ['nullable', 'string', 'max:255'],
                'kind' => ['nullable', 'in:percent,fixed,free_plan,auto_plan,code_list,manual'],
                'value' => ['nullable', 'integer', 'min:0'],
                'plan_id' => ['nullable', 'string'],
                'plan_title' => ['nullable', 'string'],
                'cost' => ['nullable', 'integer', 'min:1'],
                'stock' => ['nullable', 'integer', 'min:0'],
                'per_user_month' => ['nullable', 'integer', 'min:0'],
                'valid_days' => ['nullable', 'integer', 'min:1'],
                'starts_at' => ['nullable', 'string'],
                'ends_at' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'codes_text' => ['nullable', 'string'],
                'count' => ['nullable', 'integer', 'min:1', 'max:500'],
                'redemption_id' => ['nullable', 'integer'],
                // adjust
                'chat_id' => ['nullable', 'string'],
                'mode' => ['nullable', 'in:add,deduct'],
                'delta' => ['nullable', 'integer', 'min:1'],
                'note' => ['nullable', 'string', 'max:200'],
                'notify_reason' => ['nullable', 'boolean'],
            ]);

            $action = $validated['action'];

            try {
                if ($action === 'save_settings') {
                    $this->loyaltyService->saveSettings($validated);
                    $actionMsg = ['type' => 'success', 'text' => 'تنظیمات ذخیره شد.'];
                } elseif ($action === 'save_reward') {
                    $id = $validated['reward_id'] ?? null;
                    $result = $this->loyaltyService->saveReward($validated, $id ?: null);
                    if ($result) {
                        if (! empty($validated['codes_text'])) {
                            $added = $this->loyaltyService->addCodes($result, $validated['codes_text']);
                            $actionMsg = ['type' => 'success', 'text' => ($id ? 'جایزه ویرایش شد.' : 'جایزه ساخته شد.')." $added کد به لیست اضافه شد."];
                        } else {
                            $actionMsg = ['type' => 'success', 'text' => $id ? 'جایزه ویرایش شد.' : 'جایزه ساخته شد.'];
                        }
                    } else {
                        $actionMsg = ['type' => 'error', 'text' => 'ذخیره نشد؛ عنوان را بنویسید و برای «پلن رایگان» یا «پلن رایگان خودکار» یک پلن انتخاب کنید.'];
                    }
                } elseif ($action === 'delete_reward') {
                    $this->loyaltyService->deleteReward((int) ($validated['reward_id'] ?? 0));
                    $actionMsg = ['type' => 'success', 'text' => 'جایزه حذف شد (کدهای قبلاً صادرشده معتبر می‌مانند).'];
                } elseif ($action === 'adjust') {
                    $chatId = trim((string) ($validated['chat_id'] ?? ''));
                    if ($chatId === '' || ! User::where('chat_id', $chatId)->exists()) {
                        $actionMsg = ['type' => 'error', 'text' => 'کاربری با این آیدی پیدا نشد.'];
                    } else {
                        $amount = abs((int) ($validated['delta'] ?? 0));
                        if ($amount === 0) {
                            $actionMsg = ['type' => 'error', 'text' => 'مقدار امتیاز را به‌صورت عدد مثبت وارد کنید.'];
                        } else {
                            $mode = $validated['mode'] ?? 'deduct';
                            $delta = $mode === 'add' ? $amount : -$amount;
                            $reason = trim((string) ($validated['note'] ?? ''));
                            $notifyReason = ! empty($validated['notify_reason']);

                            $res = $this->loyaltyService->adjust($chatId, $delta, $reason, auth('admin')->id(), $notifyReason);
                            if (! empty($res['ok'])) {
                                $ap = number_format((int) $res['applied']);
                                if ($mode === 'add') {
                                    $actionMsg = ['type' => 'success', 'text' => $ap.' امتیاز به کاربر اضافه شد. موجودی جدید: '.number_format((int) $res['balance'])];
                                } else {
                                    $msg = $ap.' امتیاز از کاربر کسر شد';
                                    if ((int) $res['applied'] < (int) $res['requested']) {
                                        $msg .= ' (موجودی کاربر کمتر از '.number_format((int) $res['requested']).' بود؛ فقط همین مقدار کسر شد)';
                                    }
                                    $msg .= '. موجودی جدید: '.number_format((int) $res['balance']).'. پیام کسر امتیاز برای کاربر ارسال شد'.($notifyReason ? ' (همراه با دلیل).' : ' (بدون دلیل).');
                                    $actionMsg = ['type' => 'success', 'text' => $msg];
                                }
                            } else {
                                $actionMsg = ['type' => 'error', 'text' => $res['error'] ?? 'انجام نشد.'];
                            }
                        }
                    }
                } elseif ($action === 'generate_codes') {
                    $rewardId = (int) ($validated['reward_id'] ?? 0);
                    $count = (int) ($validated['count'] ?? 1);
                    $count = max(1, min(500, $count));
                    if (! LoyaltyReward::query()->where('id', $rewardId)->exists()) {
                        $actionMsg = ['type' => 'error', 'text' => 'جایزه انتخابشده پیدا نشد.'];
                    } else {
                        $added = $this->loyaltyService->generateCodes($rewardId, $count);
                        $actionMsg = ['type' => 'success', 'text' => "$added کد جدید با پیشوند LOY- تولید شد."];
                    }
                } elseif ($action === 'manual_paid') {
                    $redemptionId = (int) ($validated['redemption_id'] ?? 0);
                    $done = $this->loyaltyService->markManualDelivered($redemptionId, auth('admin')->id());
                    $actionMsg = $done
                        ? ['type' => 'success', 'text' => 'جایزه بهعنوان پرداختشده علامت خورد.']
                        : ['type' => 'error', 'text' => 'این جایزه قبلاً پرداخت شده یا پیدا نشد.'];
                }
            } catch (\InvalidArgumentException $e) {
                $actionMsg = ['type' => 'error', 'text' => $e->getMessage()];
            } catch (\Throwable $e) {
                $actionMsg = ['type' => 'error', 'text' => 'خطا در پردازش: '.$e->getMessage()];
            }
        }

        $settings = [];
        foreach (array_keys($this->loyaltyService->defaults()) as $k) {
            $settings[$k] = $this->loyaltyService->setting($k);
        }
        $enabled = $settings['enabled'] === '1';
        $stats = $this->loyaltyService->stats();
        $rewards = $this->loyaltyService->allRewards();
        $plans = $this->planService->sellablePlans();
        $plans = is_array($plans) ? array_values(array_filter($plans, static fn ($p) => ! array_key_exists('is_active', $p) || ! empty($p['is_active']))) : [];
        $top = $this->loyaltyService->topUsers(10);
        $kinds = $this->loyaltyService->rewardKinds();

        $lookupChat = trim((string) ($request->get('user') ?? ''));
        $lookupUser = $lookupChat !== '' ? User::where('chat_id', $lookupChat)->first() : null;

        $manualSearch = trim((string) ($request->get('mcode') ?? ''));
        $manualList = $this->loyaltyService->manualRequests(false, $manualSearch, 100);
        $manualPending = $this->loyaltyService->pendingManualCount();

        return view('admin.loyalty.index', [
            'active' => 'loyalty',
            'title' => 'سیستم امتیازدهی',
            'actionMsg' => $actionMsg,
            'settings' => $settings,
            'enabled' => $enabled,
            'stats' => $stats,
            'rewards' => $rewards,
            'plans' => $plans,
            'top' => $top,
            'kinds' => $kinds,
            'lookupChat' => $lookupChat,
            'lookupUser' => $lookupUser,
            'manualSearch' => $manualSearch,
            'manualList' => $manualList,
            'manualPending' => $manualPending,
        ]);
    }
}
