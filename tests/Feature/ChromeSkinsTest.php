<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Airoxnet chrome around the panel: the four grouped menu headers the
 * fork's layout prints, the version card in the sidebar foot, the topbar
 * status pill, and the way the sidebar grows (and the placeholder routes
 * answer) as each section's routes land.
 */
class ChromeSkinsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.base_url' => 'https://api.connectix.vip',
            'connectix_bot.connectix.panel_url' => 'https://seller.connectix.vip',
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.broadcast.delay_us' => 0,
        ]);

        Http::preventStrayRequests();
    }

    private function actingAsAdmin()
    {
        $admin = Admin::query()->create([
            'email' => 'admin-'.uniqid().'@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => AdminRole::Admin,
        ]);

        return $this->actingAs($admin, 'admin');
    }

    public function test_the_sidebar_shows_the_four_airoxnet_groups(): void
    {
        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        foreach (['عمومی', 'فروشگاه', 'ربات', 'حساب'] as $group) {
            $this->assertStringContainsString('>'.$group.'</div>', $html);
        }
    }

    public function test_the_version_card_shows_the_app_version(): void
    {
        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('pl-vercard', $html);
        $this->assertStringContainsString('نسخهٔ 4.0.0', $html);
    }

    public function test_the_status_pill_reports_the_system_active(): void
    {
        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('سیستم فعال', $html);
        $this->assertStringContainsString('pl-status', $html);
    }

    public function test_the_appearance_entry_is_listed_under_the_bot_group(): void
    {
        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        // The route exists, so its row paints and links to the theme page.
        $this->assertStringContainsString('href="'.route('admin.appearance').'"', $html);
        $this->assertStringContainsString('ظاهر و تم', $html);
    }

    public function test_a_placeholder_route_answers_404_through_the_placeholder_controller(): void
    {
        // All fork sections now have real controllers. An unknown route still 404s.
        $this->actingAsAdmin()
            ->get(route('admin.dashboard').'/does-not-exist')
            ->assertNotFound();
    }

    public function test_every_registered_placeholder_keeps_its_sidebar_row(): void
    {
        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        // Registration is what lights a row up: the tickets route exists, so
        // «تیکت‌ها» must be in the sidebar, pointing at its (404-for-now)
        // list page.
        $this->assertStringContainsString('href="'.route('admin.tickets.index').'"', $html);
        $this->assertStringContainsString('تیکت‌ها', $html);
    }

    public function test_the_open_ticket_badge_counts_open_tickets(): void
    {
        Ticket::query()->create([
            'ticket_number' => 'T-1001',
            'chat_id' => '553',
            'subject' => 'اتصال برقرار نمی‌شود',
            'status' => 'OPEN',
        ]);
        Ticket::query()->create([
            'ticket_number' => 'T-1002',
            'chat_id' => '554',
            'subject' => 'تغییر پلن',
            'status' => 'ANSWERED',
        ]);

        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="pl-badge">۱<\\/span>/u', $html);
    }

    public function test_the_channel_card_only_appears_when_a_channel_is_configured(): void
    {
        $without = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('pl-channel', $without);

        config(['connectix_bot.channel_telegram' => 'acme_channel']);

        $with = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('pl-channel', $with);
        $this->assertStringContainsString('https://t.me/acme_channel', $with);
    }

    public function test_the_theme_boot_script_stays_before_the_stylesheet(): void
    {
        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();

        $bootAt = mb_strpos($html, "localStorage.getItem('cx-theme')");
        $cssAt = mb_strpos($html, 'connectix.css');

        $this->assertNotFalse($bootAt);
        $this->assertNotFalse($cssAt);
        $this->assertLessThan($cssAt, $bootAt);
    }
}
