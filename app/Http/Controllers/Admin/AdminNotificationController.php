<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationAdminService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * یادآور و هشدار — Admin controller
 */
class AdminNotificationController extends Controller
{
    public function __construct(
        private readonly NotificationAdminService $notificationService,
    ) {}

    public function index(Request $request): View
    {
        $actionMsg = null;

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'action' => ['required', 'in:save_settings'],
                'is_active' => ['nullable', 'boolean'],
                'expiry_days_before' => ['nullable', 'string'],
                'usage_percent_thresholds' => ['nullable', 'string'],
            ]);

            $ok = $this->notificationService->saveSettings($validated);
            $actionMsg = $ok
                ? ['type' => 'success', 'text' => 'تنظیمات با موفقیت ذخیره شد.']
                : ['type' => 'error', 'text' => 'خطا در ذخیره تنظیمات.'];
        }

        $settings = $this->notificationService->getSettings();
        $dueClients = $this->notificationService->dueClients(now('Asia/Tehran'));
        $lastSent = $this->notificationService->lastSentSummary(20);

        return view('admin.notifications.index', [
            'active' => 'notifications',
            'title' => 'یادآور و هشدار',
            'settings' => $settings,
            'actionMsg' => $actionMsg,
            'dueClients' => $dueClients,
            'lastSent' => $lastSent,
        ]);
    }
}