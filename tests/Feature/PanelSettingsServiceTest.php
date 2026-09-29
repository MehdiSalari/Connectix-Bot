<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Panel\PanelSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The runtime replacement for legacy `setup/bot_config.json`.
 *
 * The legacy installer called GET /v1/seller/telegram-bot once and froze the
 * answer in a JSON file, which is what made each reseller's own app name and
 * copy visible. These tests pin the precedence rules that reproduce that
 * behaviour from a live panel.
 */
class PanelSettingsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.app_name' => null,
            'connectix_bot.admin_ids' => [],
            'connectix_bot.support_telegram' => null,
            'connectix_bot.channel_telegram' => null,
            'connectix_bot.telegram_channel_id' => null,
            'connectix_bot.card.number' => null,
            'connectix_bot.card.name' => null,
            'connectix_bot.messages' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $bot
     * @param  array<string, string>  $messages
     */
    private function fakePanel(array $bot = [], array $messages = []): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/telegram-bot' => Http::response([
                'bot' => $bot,
                'telegramMessages' => $messages,
            ], 200),
        ]);
    }

    public function test_it_reads_the_reseller_brand_from_the_seller_panel(): void
    {
        $this->fakePanel(['app_name' => 'Acme VPN']);

        $this->assertSame('Acme VPN', app(PanelSettingsService::class)->appName());
    }

    public function test_it_falls_back_to_the_project_name_when_the_panel_is_silent(): void
    {
        $this->fakePanel();

        $this->assertSame('Connectix Bot', app(PanelSettingsService::class)->appName());
    }

    public function test_a_local_override_beats_the_panel(): void
    {
        config(['connectix_bot.app_name' => 'Pinned Name']);
        $this->fakePanel(['app_name' => 'Acme VPN']);

        $this->assertSame('Pinned Name', app(PanelSettingsService::class)->appName());
    }

    public function test_it_collects_the_three_panel_admin_slots(): void
    {
        $this->fakePanel([
            'admin_id' => '111',
            'admin_id_2' => '222',
            'admin_id_3' => '333',
        ]);

        $this->assertSame(['111', '222', '333'], app(PanelSettingsService::class)->adminIds());
    }

    public function test_local_admin_ids_win_over_the_panel(): void
    {
        config(['connectix_bot.admin_ids' => ['999']]);
        $this->fakePanel(['admin_id' => '111', 'admin_id_2' => '222']);

        $this->assertSame(['999'], app(PanelSettingsService::class)->adminIds());
    }

    public function test_it_strips_a_leading_at_from_panel_handles(): void
    {
        // Legacy setup.php trimmed a leading "@" off both handles.
        $this->fakePanel([
            'support_telegram' => '@acme_support',
            'channel_telegram' => '@acme_channel',
        ]);

        $settings = app(PanelSettingsService::class);

        $this->assertSame('acme_support', $settings->supportTelegram());
        $this->assertSame('acme_channel', $settings->channelTelegram());
    }

    public function test_a_blank_panel_field_never_overrides_a_local_value(): void
    {
        config(['connectix_bot.channel_telegram' => 'local_channel']);
        $this->fakePanel(['channel_telegram' => '']);

        $this->assertSame('local_channel', app(PanelSettingsService::class)->channelTelegram());
    }

    public function test_the_string_null_sentinel_is_treated_as_absent(): void
    {
        // The installer coerced a missing channel id to the literal "null".
        $this->fakePanel(['channel_id' => 'null']);

        $this->assertNull(app(PanelSettingsService::class)->channelId());
    }

    public function test_it_resolves_messages_from_the_panel(): void
    {
        $this->fakePanel([], ['welcome_text' => 'سلام از پنل']);

        $this->assertSame('سلام از پنل', app(PanelSettingsService::class)->message('welcome_text'));
    }

    public function test_a_message_falls_back_to_the_builtin_default(): void
    {
        $this->fakePanel();

        $this->assertSame(
            'built-in',
            app(PanelSettingsService::class)->message('welcome_text', 'built-in'),
        );
    }

    public function test_a_local_message_overrides_the_panel(): void
    {
        config(['connectix_bot.messages.welcome_text' => 'متن محلی']);
        $this->fakePanel([], ['welcome_text' => 'سلام از پنل']);

        $this->assertSame('متن محلی', app(PanelSettingsService::class)->message('welcome_text'));
    }

    public function test_a_panel_outage_does_not_break_the_bot(): void
    {
        Http::fake([
            'https://api.connectix.vip/*' => Http::response(['message' => 'nope'], 500),
        ]);

        $settings = app(PanelSettingsService::class);

        $this->assertSame('Connectix Bot', $settings->appName());
        $this->assertSame('', $settings->cardNumber());
    }

    public function test_a_panel_outage_keeps_the_previously_cached_branding(): void
    {
        $this->fakePanel(['app_name' => 'Acme VPN']);
        $this->assertSame('Acme VPN', app(PanelSettingsService::class)->appName());

        // A new request must still be able to serve the last known brand.
        Cache::flush();
        Http::fake([
            'https://api.connectix.vip/*' => Http::response(['message' => 'nope'], 500),
        ]);

        Cache::put('connectix_bot.panel.telegram_bot', [
            'bot' => ['app_name' => 'Acme VPN'],
            'telegramMessages' => [],
        ], 900);

        $this->assertSame('Acme VPN', app(PanelSettingsService::class)->appName());
    }

    public function test_the_panel_is_not_called_twice_in_one_request(): void
    {
        $this->fakePanel(['app_name' => 'Acme VPN']);

        $settings = app(PanelSettingsService::class);
        $settings->appName();
        $settings->cardName();
        $settings->adminIds();

        Http::assertSentCount(1);
    }

    public function test_the_panel_can_be_switched_off_entirely(): void
    {
        config(['connectix_bot.panel.enabled' => false]);
        $this->fakePanel(['app_name' => 'Acme VPN']);

        $this->assertSame('Connectix Bot', app(PanelSettingsService::class)->appName());
        Http::assertNothingSent();
    }
}
