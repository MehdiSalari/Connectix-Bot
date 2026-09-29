<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Services\Panel\PanelSettingsService;
use App\Services\Payment\SmsPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The bot configuration page: the settings form writes local overrides (the
 * database equivalent of setup/bot_config.json), mirrors branding to the
 * seller panel, and re-registers the Telegram webhook - exactly as the legacy
 * root index.php did.
 */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.telegram.webhook_url' => '',
            'connectix_bot.telegram.webhook_secret' => '',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.base_url' => 'https://api.connectix.vip',
            'connectix_bot.app_name' => '',
            'connectix_bot.support_telegram' => '',
            'connectix_bot.channel_telegram' => '',
            'connectix_bot.telegram_channel_id' => '',
            'connectix_bot.card.number' => '',
            'connectix_bot.card.name' => '',
            'connectix_bot.bank.name' => '',
            'connectix_bot.messages' => [],
            'connectix_bot.panel.enabled' => false,
        ]);

        Http::preventStrayRequests();
    }

    private function makeAdmin(AdminRole $role = AdminRole::Admin): Admin
    {
        return Admin::query()->create([
            'email' => $role->value.'-'.uniqid().'@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => $role,
        ]);
    }

    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'app_name' => 'Nord VPN',
            'admin_id' => '11',
            'admin_id_2' => '',
            'admin_id_3' => '',
            'telegram_support' => '@support',
            'telegram_channel' => '@channel',
            'telegram_channel_id' => '-1001',
            'card_number' => '6219-8619-0000-0000',
            'card_name' => 'Nord',
            'welcome_message' => 'سلام',
            'support_message' => 'پشتیبانی',
            'faq_message' => 'سوالات',
            'free_trial_message' => 'تست',
            'bank' => 'blu',
            'bot_notice' => '1',
            'test' => '1',
            'bot_active' => '1',
            'force_channel_join' => '0',
        ], $overrides);
    }

    public function test_the_settings_page_renders_effective_values(): void
    {
        DB::table('panel_settings')->updateOrInsert(
            ['setting_key' => 'app_name'],
            ['setting_value' => 'Nord VPN', 'updated_at' => now()]
        );

        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.settings.show'))
            ->assertOk()
            ->assertSee('Nord VPN')
            ->assertSee('تنظیمات ربات');
    }

    public function test_saving_settings_persists_local_overrides(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.settings.update'), $this->settingsPayload())
            ->assertRedirect();

        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'app_name', 'setting_value' => 'Nord VPN']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'messages.welcome_text', 'setting_value' => 'سلام']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'bank.name', 'setting_value' => 'blu']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'bot_active', 'setting_value' => '1']);
    }

    public function test_the_saved_overrides_flow_back_into_the_readers(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.settings.update'), $this->settingsPayload())
            ->assertRedirect();

        $settings = app(PanelSettingsService::class);

        $this->assertSame('Nord VPN', $settings->appName());
        $this->assertSame('سلام', $settings->message('welcome_text'));
        $this->assertSame('blu', app(SmsPaymentService::class)->bankName());
        $this->assertTrue($settings->flag('connectix_bot.active', 'bot_active', true));
    }

    public function test_clearing_a_field_removes_the_override(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.settings.update'), $this->settingsPayload())
            ->assertRedirect();

        $payload = $this->settingsPayload();
        $payload['card_number'] = '';

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.settings.update'), $payload)
            ->assertRedirect();

        $this->assertDatabaseMissing('panel_settings', ['setting_key' => 'card_number']);

        $this->assertSame('', app(PanelSettingsService::class)->cardNumber());
    }

    public function test_a_toggle_on_the_page_turns_the_flag_off(): void
    {
        $payload = $this->settingsPayload(['test' => '0', 'bot_active' => '0']);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.settings.update'), $payload)
            ->assertRedirect();

        $settings = app(PanelSettingsService::class);

        $this->assertFalse($settings->flag('connectix_bot.test_enabled', 'test', true));
        $this->assertFalse($settings->flag('connectix_bot.active', 'bot_active', true));
    }

    public function test_force_channel_join_requires_the_numeric_channel_id(): void
    {
        $payload = $this->settingsPayload(['force_channel_join' => '1', 'telegram_channel_id' => '']);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.settings.update'), $payload)
            ->assertSessionHasErrors('telegram_channel_id');

        $this->assertDatabaseMissing('panel_settings', ['setting_key' => 'force_channel_join']);
    }

    public function test_the_save_is_mirrored_to_the_seller_panel_with_webhook(): void
    {
        config([
            'connectix_bot.panel.enabled' => true,
            'connectix_bot.telegram.webhook_url' => 'https://bot.example.test/telegram/webhook',
            'connectix_bot.telegram.webhook_secret' => 'wh-secret',
        ]);

        Http::fake([
            'https://api.connectix.vip/v1/seller/telegram-bot*' => Http::response([
                'bot' => [
                    'token' => 't0',
                    'is_enabled' => true,
                    'is_90_percent_plan_notifications_enabled' => true,
                    'is_expired_plan_notifications_enabled' => true,
                    'sell_mode' => 'whitelabel',
                ],
                'telegramMessages' => [],
            ], 200),
            'https://api.connectix.vip/v1/seller/telegram-bot/update-bot*' => Http::response(['status' => 'ok'], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.settings.update'), $this->settingsPayload())
            ->assertRedirect();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.connectix.vip/v1/seller/telegram-bot/update-bot'
            && $request['app_name'] === 'Nord VPN'
            && $request['channel_telegram'] === '@channel'
            && $request['admin_id_2'] === null);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org/bot')
            && $request['secret_token'] === 'wh-secret');
    }

    public function test_a_panel_failure_keeps_the_local_save_and_flags_it(): void
    {
        config(['connectix_bot.panel.enabled' => true]);

        Http::fake([
            'https://api.connectix.vip/v1/seller/telegram-bot*' => Http::response(['error' => 'down'], 500),
        ]);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.settings.update'), $this->settingsPayload())
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'app_name', 'setting_value' => 'Nord VPN']);
    }
}
