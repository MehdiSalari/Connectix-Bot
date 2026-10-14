<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\BotButton;
use App\Models\PanelSetting;
use App\Services\Bot\BotButtonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Admin Buttons page: three screen tabs, per-row inline edit, the
 * drag-order save and the default-colour card. Mutations require
 * admin.role:admin; index is admin,editor.
 */
class AdminButtonsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.base_url' => 'https://api.connectix.vip',
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.broadcast.delay_us' => 0,
        ]);

        Http::preventStrayRequests();
    }

    private function actingAsAdmin()
    {
        return $this->postingAs(AdminRole::Admin);
    }

    private function actingAsEditor()
    {
        return $this->postingAs(AdminRole::Editor);
    }

    private function postingAs(AdminRole $role)
    {
        return $this->actingAs($this->makeAdmin($role), 'admin');
    }

    private function makeAdmin(AdminRole $role): Admin
    {
        return Admin::query()->create([
            'email' => $role->value.'-'.uniqid().'@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => $role,
        ]);
    }

    /** @test */
    public function test_index_renders_main_menu_by_default(): void
    {
        $this->actingAsAdmin();
        $response = $this->get(route('admin.buttons.index'));
        $response->assertOk();
        $response->assertViewHas('screen', 'main_menu');
        $response->assertViewHas('buttons');
        $response->assertViewHas('screens');
        $response->assertViewHas('defaultColor');
    }

    /** @test */
    public function test_index_switches_screens(): void
    {
        $this->actingAsAdmin();
        foreach (['main_menu', 'buy_screen', 'os_screen'] as $screen) {
            $response = $this->get(route('admin.buttons.index', ['screen' => $screen]));
            $response->assertOk();
            $response->assertViewHas('screen', $screen);
        }
    }

    /** @test */
    public function test_editor_can_see_index_but_not_mutate(): void
    {
        $this->actingAsEditor();
        $this->get(route('admin.buttons.index'))->assertOk();

        $this->post(route('admin.buttons.update'), ['button_key' => 'x', 'current_label' => 'X'])->assertForbidden();
        $this->post(route('admin.buttons.toggle'), ['button_key' => 'x'])->assertForbidden();
        $this->post(route('admin.buttons.reorder'), ['rows' => []])->assertForbidden();
        $this->post(route('admin.buttons.default-color'), ['default_button_color' => 'blue'])->assertForbidden();
    }

    /** @test */
    public function test_upsert_creates_and_updates(): void
    {
        $this->actingAsAdmin();
        $this->post(route('admin.buttons.update'), [
            'button_key' => 'menu_buy',
            'current_label' => '🛒 خرید اشتراک',
            'emoji' => '🛒',
            'custom_emoji_id' => '',
            'button_color' => 'blue',
            'link_url' => '',
            'hide_after_test' => false,
            'is_active' => true,
        ])->assertRedirect();

        $btn = BotButton::query()->where('button_key', 'menu_buy')->first();
        $this->assertNotNull($btn);
        $this->assertEquals('🛒 خرید اشتراک', $btn->current_label);
        $this->assertEquals('🛒', $btn->emoji);
        $this->assertEquals('blue', $btn->button_color);
        // Seeded once from the first label, then never overwritten — the
        // bot's text-matching always keeps the original to fall back on.
        $this->assertEquals('🛒 خرید اشتراک', $btn->default_label);
        $this->assertTrue($btn->is_active);

        // Update same key changes label but preserves default_label
        $this->post(route('admin.buttons.update'), [
            'button_key' => 'menu_buy',
            'current_label' => '🛍 خرید',
        ])->assertRedirect();

        $btn->refresh();
        $this->assertEquals('🛍 خرید', $btn->current_label);
        // default_label still holds the very first label
        $this->assertEquals('🛒 خرید اشتراک', $btn->default_label);
    }

    /** @test */
    public function test_toggle_flips_is_active(): void
    {
        $this->actingAsAdmin();
        BotButton::query()->create([
            'button_key' => 'tst',
            'default_label' => 'T',
            'current_label' => 'T',
            'is_active' => true,
        ]);

        $this->post(route('admin.buttons.toggle'), ['button_key' => 'tst'])->assertRedirect();
        $this->assertFalse(BotButton::query()->where('button_key', 'tst')->first()->is_active);

        $this->post(route('admin.buttons.toggle'), ['button_key' => 'tst'])->assertRedirect();
        $this->assertTrue(BotButton::query()->where('button_key', 'tst')->first()->is_active);
    }

    /** @test */
    public function test_reorder_persists_sort_row_col(): void
    {
        $this->actingAsAdmin();
        $a = BotButton::query()->create(['button_key' => 'a', 'default_label' => 'A', 'current_label' => 'A', 'sort_order' => 0, 'row_num' => 1, 'col_num' => 1]);
        $b = BotButton::query()->create(['button_key' => 'b', 'default_label' => 'B', 'current_label' => 'B', 'sort_order' => 1, 'row_num' => 1, 'col_num' => 2]);

        $this->post(route('admin.buttons.reorder'), [
            'rows' => [
                ['id' => $b->id, 'sort_order' => 0, 'row_num' => 1, 'col_num' => 1],
                ['id' => $a->id, 'sort_order' => 1, 'row_num' => 1, 'col_num' => 2],
            ],
        ])->assertRedirect();

        $this->assertEquals(0, BotButton::query()->find($b->id)->sort_order);
        $this->assertEquals(1, BotButton::query()->find($a->id)->sort_order);
        $this->assertEquals(1, BotButton::query()->find($b->id)->col_num);
        $this->assertEquals(2, BotButton::query()->find($a->id)->col_num);
    }

    /** @test */
    public function test_default_color_reads_and_writes_panel_setting(): void
    {
        $this->actingAsAdmin();

        // No setting yet -> default 'blue'
        $this->get(route('admin.buttons.index'))->assertViewHas('defaultColor', 'blue');

        $this->post(route('admin.buttons.default-color'), ['default_button_color' => 'green'])->assertRedirect();
        $this->assertEquals('green', PanelSetting::query()->find('default_button_color')?->setting_value);

        $this->get(route('admin.buttons.index'))->assertViewHas('defaultColor', 'green');
    }

    /** @test */
    public function test_service_gets_buy_screen_buttons_with_fallbacks(): void
    {
        $this->actingAsAdmin();
        $svc = app(BotButtonService::class);

        BotButton::query()->create([
            'button_key' => 'renew_flow',
            'default_label' => 'تمدید',
            'current_label' => 'تمدید',
            'screen' => 'buy_screen',
            'is_active' => true,
        ]);
        BotButton::query()->create([
            'button_key' => 'buy_new_flow',
            'default_label' => 'خرید جدید',
            'current_label' => 'خرید جدید',
            'screen' => 'buy_screen',
            'is_active' => true,
        ]);

        $btns = $svc->getBuyScreenButtons();
        $this->assertCount(2, $btns);
        // The keys resolve to the fork's callbacks: renew_flow → renew,
        // buy_new_flow → group. button_key stays the storage key.
        $callbacks = array_column($btns, 'callback_data');
        $this->assertContains('renew', $callbacks);
        $this->assertContains('group', $callbacks);
        $keys = array_column($btns, 'button_key');
        $this->assertContains('renew_flow', $keys);
        $this->assertContains('buy_new_flow', $keys);
    }

    /** @test */
    public function test_service_gets_os_screen_buttons_mapped(): void
    {
        $this->actingAsAdmin();
        $svc = app(BotButtonService::class);

        foreach (['app_windows', 'app_android', 'app_ios', 'app_linux', 'app_mac'] as $key) {
            BotButton::query()->create([
                'button_key' => $key,
                'default_label' => $key,
                'current_label' => $key,
                'screen' => 'os_screen',
                'is_active' => true,
                'link_url' => 'https://example.com/'.$key,
                'action_type' => 'open_url',
            ]);
        }

        $btns = $svc->getOsScreenButtons();
        $this->assertCount(5, $btns);
        foreach ($btns as $b) {
            $this->assertArrayHasKey('link_url', $b);
            $this->assertStringStartsWith('https://example.com/', $b['link_url']);
        }
    }
}
