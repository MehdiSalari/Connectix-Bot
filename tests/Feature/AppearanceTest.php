<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\PanelSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ظاهر و تم: the theme manager page.
 *
 * The page must list the ten themes the ported fork palette defines, persist
 * the panel/webapp defaults as panel_settings rows, refuse a theme id that is
 * not one of the ten, and stay behind the admin login.
 */
class AppearanceTest extends TestCase
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

    public function test_the_page_renders_the_ten_theme_names(): void
    {
        $this->actingAsAdmin()
            ->get(route('admin.appearance'))
            ->assertOk()
            ->assertSee('تیره بنفش')
            ->assertSee('روشن بنفش')
            ->assertSee('تیره طلایی')
            ->assertSee('اقیانوس')
            ->assertSee('جنگل')
            ->assertSee('غروب')
            ->assertSee('رز')
            ->assertSee('نیمه‌شب')
            ->assertSee('گرافیت');
    }

    public function test_the_page_carries_the_appearance_active_key_and_title(): void
    {
        $html = $this->actingAsAdmin()->get(route('admin.appearance'))->assertOk()->getContent();

        $this->assertStringContainsString('ظاهر و تم', $html);
        $this->assertStringContainsString('data-theme-id="airox-gold"', $html);
        $this->assertStringContainsString('data-theme-id="airox-violet"', $html);
    }

    public function test_posting_a_theme_persists_both_panel_setting_rows(): void
    {
        $this->actingAsAdmin()
            ->from(route('admin.appearance'))
            ->post(route('admin.appearance.store'), [
                'panel_theme' => 'airox-gold',
                'webapp_theme' => 'ocean',
            ])
            ->assertRedirect(route('admin.appearance'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'panel_theme', 'setting_value' => 'airox-gold']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'webapp_theme', 'setting_value' => 'ocean']);
    }

    public function test_posting_a_theme_without_the_webapp_key_clears_that_override(): void
    {
        PanelSetting::query()->create(['setting_key' => 'webapp_theme', 'setting_value' => 'forest']);

        $this->actingAsAdmin()
            ->from(route('admin.appearance'))
            ->post(route('admin.appearance.store'), ['panel_theme' => 'rose'])
            ->assertRedirect(route('admin.appearance'));

        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'panel_theme', 'setting_value' => 'rose']);
        $this->assertDatabaseMissing('panel_settings', ['setting_key' => 'webapp_theme']);
    }

    public function test_an_invalid_theme_is_rejected(): void
    {
        $this->actingAsAdmin()
            ->from(route('admin.appearance'))
            ->post(route('admin.appearance.store'), ['panel_theme' => 'not-a-theme'])
            ->assertSessionHasErrors('panel_theme');

        $this->assertDatabaseMissing('panel_settings', ['setting_value' => 'not-a-theme']);
    }

    public function test_an_editor_may_read_but_not_write(): void
    {
        $editor = Admin::query()->create([
            'email' => 'editor-'.uniqid().'@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-b',
            'chat_id' => '11',
            'role' => AdminRole::Editor,
        ]);

        $this->actingAs($editor, 'admin')
            ->get(route('admin.appearance'))
            ->assertOk();

        $this->actingAs($editor, 'admin')
            ->from(route('admin.appearance'))
            ->post(route('admin.appearance.store'), ['panel_theme' => 'forest'])
            ->assertForbidden();

        $this->assertDatabaseMissing('panel_settings', ['setting_value' => 'forest']);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('admin.appearance'))
            ->assertRedirect(route('admin.login'));
    }
}
