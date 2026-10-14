<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\CustomPlanSetting;
use App\Services\Shop\CustomPlanConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The custom plan settings: the single-row save, the min<=max rule on both
 * axes, and the bot texts landing in panel_settings.
 */
class AdminCustomPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.app_name' => 'Acme VPN',
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'is_active' => '1',
            'price_per_gb' => '5000',
            'price_per_day' => '1000',
            'min_gb' => '1',
            'max_gb' => '1000',
            'min_days' => '7',
            'max_days' => '365',
            'default_devices' => '2',
        ], $overrides);
    }

    public function test_the_page_renders_the_factory_defaults(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.custom-plan.index'))
            ->assertOk()
            ->assertSee('پلن سفارشی')
            ->assertSee('قیمت هر گیگابایت');

        $this->assertSame(5000, app(CustomPlanConfigService::class)->priceFor(1, 0));
    }

    public function test_saving_creates_the_single_settings_row(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.custom-plan.update'), $this->payload())
            ->assertRedirect(route('admin.custom-plan.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, CustomPlanSetting::query()->count());

        $row = CustomPlanSetting::query()->findOrFail(1);

        $this->assertTrue((bool) $row->is_active);
        $this->assertSame('5000', (string) $row->price_per_gb);
        $this->assertSame('1000', (string) $row->price_per_day);
        $this->assertSame('1.00', (string) $row->min_gb);
        $this->assertSame('1000.00', (string) $row->max_gb);
        $this->assertSame(7, $row->min_days);
        $this->assertSame(365, $row->max_days);
        $this->assertSame(2, $row->default_devices);
    }

    public function test_saving_again_updates_the_same_row(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')->post(route('admin.custom-plan.update'), $this->payload())->assertRedirect();
        $this->actingAs($admin, 'admin')->post(route('admin.custom-plan.update'), $this->payload([
            'price_per_gb' => '7000',
            'is_active' => null,
        ]))->assertRedirect();

        $this->assertSame(1, CustomPlanSetting::query()->count());

        $row = CustomPlanSetting::query()->findOrFail(1);

        $this->assertSame('7000', (string) $row->price_per_gb);
        $this->assertFalse((bool) $row->is_active);
    }

    public function test_a_max_below_min_is_refused_on_both_axes(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.custom-plan.update'), $this->payload([
                'max_gb' => '0.5',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['max_gb']);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.custom-plan.update'), $this->payload([
                'max_days' => '3',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['max_days']);

        $this->assertSame(0, CustomPlanSetting::query()->count());
    }

    public function test_the_bot_texts_persist_to_panel_settings(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.custom-plan.update'), $this->payload([
                'text_ask_gb' => '📐 چند گیگ می‌خواهی؟',
                'text_ask_days' => '⏳ مدت زمان را انتخاب کن',
                'text_summary_title' => '📋 سفارش شما:',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'customplan_text_ask_gb', 'setting_value' => '📐 چند گیگ می‌خواهی؟']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'customplan_text_ask_days', 'setting_value' => '⏳ مدت زمان را انتخاب کن']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'customplan_text_summary_title', 'setting_value' => '📋 سفارش شما:']);

        // They read back merged with the row, the way the bot flow will.
        $settings = app(CustomPlanConfigService::class)->all();

        $this->assertSame('📐 چند گیگ می‌خواهی؟', $settings['text_ask_gb']);

        // A cleared text goes back to the default (the override is deleted).
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.custom-plan.update'), $this->payload([
                'text_ask_gb' => '',
            ]))
            ->assertRedirect();

        $this->assertDatabaseMissing('panel_settings', ['setting_key' => 'customplan_text_ask_gb']);
    }

    public function test_an_editor_can_read_but_not_mutate(): void
    {
        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->get(route('admin.custom-plan.index'))
            ->assertOk();

        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->post(route('admin.custom-plan.update'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, CustomPlanSetting::query()->count());
        $this->assertSame(0, DB::table('panel_settings')->where('setting_key', 'like', 'customplan_text_%')->count());
    }
}
