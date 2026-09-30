<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\ConnectixApiException;
use App\Exceptions\TelegramApiException;
use App\Http\Controllers\Controller;
use App\Services\Connectix\ConnectixService;
use App\Services\Panel\PanelSettingsService;
use App\Services\Telegram\TelegramProfileService;
use App\Services\Payment\SmsPaymentService;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The bot configuration page.
 *
 * Port of the settings section of the legacy root index.php. The form writes
 * every editable field to the local override store (the database equivalent of
 * setup/bot_config.json), mirrors app branding up to the seller panel with
 * `update-bot`, and re-registers the Telegram webhook the way legacy did after
 * a successful save.
 */
class AdminSettingsController extends Controller
{
    public function __construct(
        private readonly PanelSettingsService $settings,
        private readonly SmsPaymentService $sms,
    ) {}

    public function show(): View
    {
        $adminIds = $this->settings->adminIds();

        $effective = [
            'app_name' => $this->settings->appName(),
            'admin_id' => $adminIds[0] ?? '',
            'admin_id_2' => $adminIds[1] ?? '',
            'admin_id_3' => $adminIds[2] ?? '',
            'support_telegram' => $this->settings->supportTelegram(),
            'channel_telegram' => $this->settings->channelTelegram(),
            'telegram_channel_id' => $this->settings->channelId() ?? '',
            'card_number' => $this->settings->cardNumber(),
            'card_name' => $this->settings->cardName(),
            'messages' => $this->settings->messages(),
            'bank' => $this->sms->bankName() ?? '',
            'bank_bot_notice' => $this->sms->botNotice(),
            'test' => $this->settings->flag('connectix_bot.test_enabled', 'test', true),
            'bot_active' => $this->settings->flag('connectix_bot.active', 'bot_active', true),
            'force_channel_join' => $this->settings->flag('connectix_bot.force_channel_join', 'force_channel_join', false),
        ];

        return view('admin.settings.index', [
            'appName' => $this->settings->appName(),
            // عکس ربات، همان‌طور که پنل قدیمی می‌گرفت: از t.me ربات.
            'bot' => app(TelegramProfileService::class)->botProfile(),
            'effective' => $effective,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_name' => ['required', 'string', 'max:190'],
            'admin_id' => ['nullable', 'string', 'max:50'],
            'admin_id_2' => ['nullable', 'string', 'max:50'],
            'admin_id_3' => ['nullable', 'string', 'max:50'],
            'telegram_support' => ['nullable', 'string', 'max:190'],
            'telegram_channel' => ['nullable', 'string', 'max:190'],
            'telegram_channel_id' => ['required_if:force_channel_join,1', 'nullable', 'string', 'max:50'],
            'card_number' => ['nullable', 'string', 'max:30'],
            'card_name' => ['nullable', 'string', 'max:190'],
            'welcome_message' => ['nullable', 'string'],
            'support_message' => ['nullable', 'string'],
            'faq_message' => ['nullable', 'string'],
            'free_trial_message' => ['nullable', 'string'],
            'bank' => ['nullable', 'string', 'max:190'],
            'bot_notice' => ['sometimes', Rule::in(['0', '1'])],
            'test' => ['sometimes', Rule::in(['0', '1'])],
            'bot_active' => ['sometimes', Rule::in(['0', '1'])],
            'force_channel_join' => ['sometimes', Rule::in(['0', '1'])],
        ]);

        $values = [
            'app_name' => $data['app_name'],
            'admin_id' => $data['admin_id'] ?? '',
            'admin_id_2' => $data['admin_id_2'] ?? '',
            'admin_id_3' => $data['admin_id_3'] ?? '',
            'support_telegram' => $data['telegram_support'] ?? '',
            'channel_telegram' => ltrim((string) ($data['telegram_channel'] ?? ''), '@'),
            'telegram_channel_id' => $data['telegram_channel_id'] ?? '',
            'card_number' => $data['card_number'] ?? '',
            'card_name' => $data['card_name'] ?? '',
            'messages.welcome_text' => $data['welcome_message'] ?? '',
            'messages.contact_support' => $data['support_message'] ?? '',
            'messages.questions_and_answers' => $data['faq_message'] ?? '',
            'messages.free_test_account_created' => $data['free_trial_message'] ?? '',
            'bank.name' => $data['bank'] ?? '',
            'bank.bot_notice' => $request->boolean('bot_notice') ? '1' : '0',
            'test' => $request->boolean('test') ? '1' : '0',
            'bot_active' => $request->boolean('bot_active') ? '1' : '0',
            'force_channel_join' => $request->boolean('force_channel_join') ? '1' : '0',
        ];

        $this->settings->saveOverrides($values);

        $panelStatus = $this->syncPanel($values);

        $webhookStatus = $this->reRegisterWebhook();

        $notice = 'تنظیمات با موفقیت ذخیره شد.';

        if ($panelStatus === 'error') {
            $notice .= ' ذخیره‌سازی در پنل Connectix خطا داشت (تنظیمات محلی ذخیره شد).';
        }

        if ($webhookStatus === 'error') {
            $notice .= ' تنظیم مجدد وب‌هوک خطا داشت.';
        }

        return $webhookStatus === 'error' || $panelStatus === 'error'
            ? back()->with('warning', $notice)
            : back()->with('success', $notice);
    }

    /**
     * Mirror the edited branding up to the seller panel, exactly as the legacy
     * settings form called /update-bot with the current bot payload.
     */
    private function syncPanel(array $values): string
    {
        if (! config('connectix_bot.panel.enabled', true)) {
            return 'skipped';
        }

        try {
            $bot = app(ConnectixService::class)->getTelegramBotConfig()['bot'];

            $body = [
                'app_name' => $values['app_name'],
                'support_telegram' => $values['support_telegram'],
                'channel_id' => $values['telegram_channel_id'],
                'channel_telegram' => '@'.ltrim($values['channel_telegram'], '@'),
                'token' => $this->string($bot['token'] ?? ''),
                'card_number' => $values['card_number'],
                'card_name' => $values['card_name'],
                'is_enabled' => (bool) ($bot['is_enabled'] ?? true),
                'admin_id' => $values['admin_id'],
                'admin_id_2' => $values['admin_id_2'] !== '' ? $values['admin_id_2'] : null,
                'admin_id_3' => $values['admin_id_3'] !== '' ? $values['admin_id_3'] : null,
                'is_90_percent_plan_notifications_enabled' => (bool) ($bot['is_90_percent_plan_notifications_enabled'] ?? true),
                'is_expired_plan_notifications_enabled' => (bool) ($bot['is_expired_plan_notifications_enabled'] ?? true),
                'sell_mode' => $this->string($bot['sell_mode'] ?? ''),
            ];

            app(ConnectixService::class)->updateTelegramBot($body);

            return 'ok';
        } catch (ConnectixApiException $e) {
            Log::warning('Setting save could not be mirrored on the seller panel.', [
                'error' => $e->getMessage(),
            ]);

            return 'error';
        }
    }

    /**
     * Re-register the webhook after a successful save, as the legacy settings
     * form did. Only when the public URL is known from configuration; the same
     * policy as the telegram:webhook command.
     */
    private function reRegisterWebhook(): string
    {
        $url = (string) config('connectix_bot.telegram.webhook_url');
        $secret = (string) config('connectix_bot.telegram.webhook_secret');

        if ($url === '' || $secret === '') {
            return 'skipped';
        }

        try {
            app(TelegramService::class)->setWebhook($url, [
                'secret_token' => $secret,
                'max_connections' => 40,
                'allowed_updates' => ['message', 'edited_message', 'callback_query'],
            ]);

            return 'ok';
        } catch (TelegramApiException $e) {
            Log::warning('Webhook could not be re-registered after a setting save.', [
                'error' => $e->getMessage(),
            ]);

            return 'error';
        }
    }

    private function string(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }
}
