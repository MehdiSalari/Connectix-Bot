<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Winback\WinbackAdminService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * بازگشت مشتری — Admin controller.
 *
 * One route handles the GET page and every POST action (settings, start,
 * stop). The actual sending stays with the cron/bot layer: startCampaign()
 * only fills the queue, so this page never talks to Telegram.
 */
class AdminWinbackController extends Controller
{
    public function __construct(
        private readonly WinbackAdminService $winbackService,
    ) {}

    public function index(Request $request): View
    {
        $actionMsg = null;

        if ($request->isMethod('post')) {
            $action = (string) $request->input('action', '');

            try {
                if ($action === 'save_settings') {
                    $validated = $request->validate([
                        'is_active' => ['nullable', 'boolean'],
                        'inactive_days' => ['required', 'integer', 'min:1', 'max:3650'],
                        'message_text' => ['required', 'string', 'max:2000'],
                        'coupon_code' => ['nullable', 'string', 'max:64'],
                    ]);

                    $this->winbackService->saveSettings($validated);
                    $actionMsg = ['type' => 'success', 'text' => 'تنظیمات بازگشت مشتری ذخیره شد.'];
                } elseif ($action === 'start') {
                    $result = $this->winbackService->startCampaign(auth('admin')->id());

                    $actionMsg = ['type' => $result['success'] ? 'success' : 'error', 'text' => $result['message']];
                } elseif ($action === 'stop') {
                    $cancelled = $this->winbackService->stopCampaign();
                    $actionMsg = ['type' => 'success', 'text' => number_format($cancelled) . ' پیام از صف ارسال خارج شد.'];
                }
            } catch (\Illuminate\Validation\ValidationException $e) {
                $actionMsg = ['type' => 'error', 'text' => 'اطلاعات واردشده معتبر نیست.'];
            } catch (\Throwable $e) {
                $actionMsg = ['type' => 'error', 'text' => 'خطا در پردازش: ' . $e->getMessage()];
            }
        }

        $settings = $this->winbackService->getSettings();
        $queued = $this->winbackService->getQueuedCount();
        $eligible = $this->winbackService->countEligibleUsers((int) $settings['inactive_days']);

        return view('admin.winback.index', [
            'active' => 'winback',
            'title' => 'بازگشت مشتری',
            'actionMsg' => $actionMsg,
            'settings' => $settings,
            'queued' => $queued,
            'eligible' => $eligible,
            'logs' => $this->winbackService->getRecentLogs(15),
        ]);
    }
}