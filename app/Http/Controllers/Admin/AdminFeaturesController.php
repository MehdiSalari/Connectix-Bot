<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PanelSetting;
use App\Services\Features\DbBackupAdminService;
use App\Services\Features\StartEmojiAdminService;
use App\Services\Features\WelcomeCreditAdminService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ویژگیهای ویژه — brand, start emoji, welcome credit and database backup.
 *
 * Port of the fork's features/index.php. The setup lock/unlock section is
 * intentionally not ported (the installer is guarded differently here).
 */
class AdminFeaturesController extends Controller
{
    public function index(Request $request): View
    {
        $actionMsg = null;

        if ($request->isMethod('post')) {
            $form = (string) $request->input('form', '');

            try {
                if ($form === 'brand') {
                    $validated = $request->validate([
                        'brand_name' => ['nullable', 'string', 'max:60'],
                        'brand_channel_url' => ['nullable', 'string', 'max:200'],
                    ]);

                    $name = trim((string) ($validated['brand_name'] ?? ''));
                    PanelSetting::query()->updateOrInsert(
                        ['setting_key' => 'brand_name'],
                        ['setting_value' => $name !== '' ? $name : 'Airoxnet']
                    );
                    PanelSetting::query()->updateOrInsert(
                        ['setting_key' => 'brand_channel_url'],
                        ['setting_value' => trim((string) ($validated['brand_channel_url'] ?? ''))]
                    );

                    $actionMsg = ['type' => 'success', 'text' => 'تنظیمات برند ذخیره شد.'];
                } elseif ($form === 'start_emoji') {
                    $validated = $request->validate([
                        'emoji_enabled' => ['nullable', 'boolean'],
                        'emoji_char' => ['nullable', 'string', 'max:8'],
                        'custom_emoji_id' => ['nullable', 'string', 'max:32'],
                    ]);

                    StartEmojiAdminService::save([
                        'enabled' => ! empty($validated['emoji_enabled']),
                        'emoji_char' => $validated['emoji_char'] ?? '',
                        'custom_emoji_id' => $validated['custom_emoji_id'] ?? '',
                    ]);

                    $actionMsg = ['type' => 'success', 'text' => 'تنظیمات ایموجی استارت ذخیره شد.'];
                } elseif ($form === 'welcome_credit') {
                    $validated = $request->validate([
                        'wc_enabled' => ['nullable', 'boolean'],
                        'wc_amount' => ['nullable', 'string'],
                        'wc_starts_at' => ['nullable', 'string'],
                        'wc_ends_at' => ['nullable', 'string'],
                        'wc_notify' => ['nullable', 'boolean'],
                        'wc_message' => ['nullable', 'string', 'max:500'],
                    ]);

                    WelcomeCreditAdminService::save([
                        'enabled' => ! empty($validated['wc_enabled']),
                        'amount' => $validated['wc_amount'] ?? 0,
                        'starts_at' => $validated['wc_starts_at'] ?? '',
                        'ends_at' => $validated['wc_ends_at'] ?? '',
                        'notify' => ! empty($validated['wc_notify']),
                        'message' => $validated['wc_message'] ?? '',
                    ]);

                    $actionMsg = ['type' => 'success', 'text' => 'کمپین اعتبار خوشآمدگویی ذخیره شد.'];
                } elseif ($form === 'db_backup_schedule') {
                    $validated = $request->validate([
                        'bk_enabled' => ['nullable', 'boolean'],
                        'bk_frequency' => ['nullable', 'in:daily,weekly'],
                        'bk_time' => ['nullable', 'string'],
                        'bk_weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
                        'bk_target' => ['nullable', 'in:admin_id,admin_id_2,admin_id_3,admin2,admin3,custom'],
                        'bk_custom_chat_id' => ['nullable', 'string', 'max:32'],
                    ]);

                    DbBackupAdminService::save([
                        'enabled' => ! empty($validated['bk_enabled']),
                        'frequency' => $validated['bk_frequency'] ?? 'daily',
                        'time' => $validated['bk_time'] ?? '03:00',
                        'weekday' => $validated['bk_weekday'] ?? 1,
                        'target' => $validated['bk_target'] ?? 'admin_id',
                        'custom_chat_id' => $validated['bk_custom_chat_id'] ?? '',
                    ]);

                    $actionMsg = ['type' => 'success', 'text' => 'زمان‌بندی ارسال خودکار بکاپ ذخیره شد.'];
                } elseif ($form === 'db_backup_send_now') {
                    $result = DbBackupAdminService::sendNow();
                    $actionMsg = $result['ok']
                        ? ['type' => 'success', 'text' => 'بکاپ ساخته و ارسال شد.']
                        : ['type' => 'error', 'text' => 'بکاپ ساخته شد؛ '.($result['error'] ?? 'ارسال انجام نشد.')];
                }
            } catch (ValidationException $e) {
                $actionMsg = ['type' => 'error', 'text' => 'اطلاعات واردشده معتبر نیست.'];
            } catch (\Throwable $e) {
                $actionMsg = ['type' => 'error', 'text' => 'خطا در پردازش: '.$e->getMessage()];
            }
        }

        $brandName = PanelSetting::query()->where('setting_key', 'brand_name')->value('setting_value') ?: 'Airoxnet';
        $brandChannel = (string) (PanelSetting::query()->where('setting_key', 'brand_channel_url')->value('setting_value') ?? '');

        return view('admin.features.index', [
            'active' => 'features',
            'title' => 'ویژگیهای ویژه',
            'actionMsg' => $actionMsg,
            'brandName' => $brandName,
            'brandChannel' => $brandChannel,
            'startEmoji' => StartEmojiAdminService::get(),
            'welcomeCredit' => WelcomeCreditAdminService::get(),
            'welcomeCreditStats' => WelcomeCreditAdminService::stats(),
            'backup' => DbBackupAdminService::get(),
        ]);
    }
}
