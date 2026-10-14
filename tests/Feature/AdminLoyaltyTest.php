<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\LoyaltyCode;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyReward;
use App\Models\User;
use App\Services\Loyalty\LoyaltyAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * سیستم امتیازدهی — the loyalty admin page: settings persisted as
 * panel_settings loyalty_* keys, rewards CRUD, LOY- code generation, the
 * manual point adjust with its never-below-zero clamp, and the admin-only
 * boundary on the POST route.
 */
class AdminLoyaltyTest extends TestCase
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
        ]);

        Http::preventStrayRequests();

        // The page lists the sellable plans for the plan-reward form.
        Http::fake([
            'api.connectix.vip/v1/seller/seller-plans*' => Http::response([
                'seller_plan_group' => [[
                    'name' => 'Premium',
                    'seller_plans' => [[
                        'id' => 'plan-x',
                        'title' => '(2x) 30GB-1M + 3D + Economic',
                        'type' => 'Premium',
                        'is_displayed_in_robot' => true,
                    ]],
                ]],
            ]),
        ]);
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

    private function postingAs(AdminRole $role = AdminRole::Admin)
    {
        return $this->actingAs($this->makeAdmin($role), 'admin');
    }

    private function makeUser(string $chatId = '777'): User
    {
        return User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => 'tg_'.$chatId,
            'name' => 'Ali',
            'created_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function test_saving_the_settings_persists_loyalty_panel_settings(): void
    {
        $this->postingAs()
            ->post(route('admin.loyalty.store'), [
                'action' => 'save_settings',
                'enabled' => '1',
                'rate_toman' => '2000',
                'first_bonus' => '25',
                'daily_enabled' => '1',
                'daily_points' => '2',
                'expiry_months' => '6',
            ])
            ->assertOk()
            ->assertSee('تنظیمات ذخیره شد.');

        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'loyalty_enabled', 'setting_value' => '1']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'loyalty_rate_toman', 'setting_value' => '2000']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'loyalty_first_bonus', 'setting_value' => '25']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'loyalty_daily_enabled', 'setting_value' => '1']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'loyalty_daily_points', 'setting_value' => '2']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'loyalty_expiry_months', 'setting_value' => '6']);

        // The service reads the same keys back.
        $this->assertTrue(LoyaltyAdminService::enabled());
        $this->assertSame('2000', LoyaltyAdminService::setting('rate_toman'));
    }

    // -----------------------------------------------------------------
    // Rewards CRUD
    // -----------------------------------------------------------------

    public function test_a_reward_is_created_and_deleted_from_the_page(): void
    {
        $this->postingAs()
            ->post(route('admin.loyalty.store'), [
                'action' => 'save_reward',
                'title' => '۲۰ درصد تخفیف',
                'kind' => 'percent',
                'value' => '20',
                'cost' => '500',
                'is_active' => '1',
            ])
            ->assertOk()
            ->assertSee('جایزه ساخته شد.');

        $reward = LoyaltyReward::query()->sole();
        $this->assertSame('۲۰ درصد تخفیف', $reward->title);
        $this->assertSame('percent', $reward->kind);
        $this->assertSame(20, (int) $reward->value);
        $this->assertSame(500, (int) $reward->cost);
        $this->assertTrue((bool) $reward->is_active);

        $this->postingAs()
            ->post(route('admin.loyalty.store'), [
                'action' => 'delete_reward',
                'reward_id' => $reward->id,
            ])
            ->assertOk()
            ->assertSee('جایزه حذف شد');

        $this->assertDatabaseMissing('loyalty_rewards', ['id' => $reward->id]);
    }

    public function test_a_reward_without_a_title_is_refused(): void
    {
        $this->postingAs()
            ->post(route('admin.loyalty.store'), [
                'action' => 'save_reward',
                'title' => '   ',
                'kind' => 'percent',
                'value' => '20',
            ])
            ->assertOk()
            ->assertSee('ذخیره نشد');

        $this->assertDatabaseCount('loyalty_rewards', 0);
    }

    // -----------------------------------------------------------------
    // Code generation
    // -----------------------------------------------------------------

    public function test_generating_three_codes_writes_three_unique_loy_codes(): void
    {
        $id = LoyaltyAdminService::saveReward([
            'title' => 'جایزه کدی',
            'kind' => 'code_list',
            'cost' => '100',
            'is_active' => '1',
        ]);
        $this->assertNotNull($id);

        $this->postingAs()
            ->post(route('admin.loyalty.store'), [
                'action' => 'generate_codes',
                'reward_id' => $id,
                'count' => '3',
            ])
            ->assertOk()
            ->assertSee('3 کد جدید با پیشوند LOY- تولید شد.');

        $codes = LoyaltyCode::query()->where('reward_id', $id)->pluck('code');
        $this->assertCount(3, $codes);
        $this->assertCount(3, $codes->unique());

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^LOY-[A-Z0-9]{8}$/', $code);
        }
    }

    // -----------------------------------------------------------------
    // Manual adjust
    // -----------------------------------------------------------------

    public function test_an_admin_adjustment_adds_points_and_records_the_ledger_row(): void
    {
        $this->makeUser('777');

        $this->postingAs()
            ->post(route('admin.loyalty.store'), [
                'action' => 'adjust',
                'chat_id' => '777',
                'mode' => 'add',
                'delta' => '500',
                'note' => 'هدیه تبلیغات',
            ])
            ->assertOk()
            ->assertSee('500 امتیاز به کاربر اضافه شد.');

        $this->assertDatabaseHas('loyalty_points', [
            'chat_id' => '777',
            'amount' => 500,
            'remaining' => 500,
            'type' => 'admin',
        ]);
        $this->assertSame(500, LoyaltyAdminService::balance('777'));
    }

    public function test_a_deduction_larger_than_the_balance_clamps_at_zero(): void
    {
        $this->makeUser('777');
        LoyaltyAdminService::adjust('777', 300, 'هدیه اولیه', 1);

        // Take 999 out of a 300 balance: only 300 can go, and the page must
        // say the deduction was partial instead of silently taking all.
        $this->postingAs()
            ->post(route('admin.loyalty.store'), [
                'action' => 'adjust',
                'chat_id' => '777',
                'mode' => 'deduct',
                'delta' => '999',
            ])
            ->assertOk()
            ->assertSee('فقط همین مقدار کسر شد');

        // The balance is zero, never negative.
        $this->assertSame(0, LoyaltyAdminService::balance('777'));
        $this->assertSame(
            0,
            (int) LoyaltyPoint::query()->where('chat_id', '777')->where('remaining', '<', 0)->count()
        );
    }

    // -----------------------------------------------------------------
    // Role gating
    // -----------------------------------------------------------------

    public function test_an_editor_can_read_the_page_but_not_save_settings(): void
    {
        $this->postingAs(AdminRole::Editor)
            ->get(route('admin.loyalty.index'))
            ->assertOk();

        $this->postingAs(AdminRole::Editor)
            ->post(route('admin.loyalty.store'), [
                'action' => 'save_settings',
                'enabled' => '1',
                'rate_toman' => '2000',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('panel_settings', ['setting_key' => 'loyalty_enabled']);
    }
}
