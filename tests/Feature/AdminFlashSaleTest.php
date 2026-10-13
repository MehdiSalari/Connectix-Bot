<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\FlashSale;
use App\Services\Shop\FlashSaleAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * حراج زماندار — the flash-sale admin page: the create/toggle/delete
 * roundtrip, the validation rules (end after start, percent 1..99), the
 * price maths with its zero floor, the active-sale map the shop layer will
 * consume, and the admin-only boundary on the POST route.
 */
class AdminFlashSaleTest extends TestCase
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

        // The page resolves the chosen plan id against the seller catalogue,
        // so the catalogue call is faked with the plan the fixtures use.
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

    // -----------------------------------------------------------------
    // CRUD roundtrip
    // -----------------------------------------------------------------

    public function test_a_flash_sale_is_created_toggled_and_deleted_from_the_page(): void
    {
        $this->postingAs()
            ->post(route('admin.flash-sales.store'), [
                'action' => 'create',
                'plan_id' => 'plan-x',
                'discount_type' => 'percent',
                'discount_value' => 20,
                'starts_at' => '2026-10-20T10:00',
                'ends_at' => '2026-10-21T10:00',
            ])
            ->assertOk()
            ->assertSee('حراج ثبت شد.');

        $sale = FlashSale::query()->sole();
        $this->assertSame('plan-x', $sale->plan_id);
        $this->assertSame('(2x) 30GB-1M + 3D + Economic', $sale->plan_title);
        $this->assertSame('percent', $sale->discount_type);
        $this->assertSame(20, (int) $sale->discount_value);
        $this->assertSame('2026-10-20 10:00:00', $sale->starts_at);
        $this->assertSame('2026-10-21 10:00:00', $sale->ends_at);
        $this->assertTrue((bool) $sale->is_active);

        $this->postingAs()
            ->post(route('admin.flash-sales.store'), [
                'action' => 'toggle',
                'id' => $sale->id,
                'to' => '0',
            ])
            ->assertOk()
            ->assertSee('وضعیت حراج تغییر کرد.');

        $this->assertDatabaseHas('flash_sales', ['id' => $sale->id, 'is_active' => 0]);

        $this->postingAs()
            ->post(route('admin.flash-sales.store'), [
                'action' => 'delete',
                'id' => $sale->id,
            ])
            ->assertOk()
            ->assertSee('حراج حذف شد.');

        $this->assertDatabaseMissing('flash_sales', ['id' => $sale->id]);
    }

    public function test_a_sale_that_ends_before_it_starts_is_refused(): void
    {
        $this->postingAs()
            ->post(route('admin.flash-sales.store'), [
                'action' => 'create',
                'plan_id' => 'plan-x',
                'discount_type' => 'percent',
                'discount_value' => 20,
                'starts_at' => '2026-10-21T10:00',
                'ends_at' => '2026-10-20T10:00',
            ])
            ->assertOk()
            ->assertSee('زمان پایان باید بعد از زمان شروع باشد.');

        $this->assertDatabaseCount('flash_sales', 0);
    }

    public function test_a_percent_over_99_is_refused(): void
    {
        $this->postingAs()
            ->post(route('admin.flash-sales.store'), [
                'action' => 'create',
                'plan_id' => 'plan-x',
                'discount_type' => 'percent',
                'discount_value' => 120,
                'starts_at' => '2026-10-20T10:00',
                'ends_at' => '2026-10-21T10:00',
            ])
            ->assertOk()
            ->assertSee('درصد تخفیف باید بین ۱ تا ۹۹ باشد.');

        $this->assertDatabaseCount('flash_sales', 0);
    }

    // -----------------------------------------------------------------
    // Price maths
    // -----------------------------------------------------------------

    public function test_price_with_sale_applies_percent_and_fixed_and_floors_at_zero(): void
    {
        // No sale at all: the plan price stands, no strike price.
        $this->assertSame([100000, 0], FlashSaleAdminService::priceWithSale(100000, null));

        // Percent keeps the original as the strike price.
        $this->assertSame([80000, 100000], FlashSaleAdminService::priceWithSale(100000, [
            'discount_type' => 'percent', 'discount_value' => 20,
        ]));

        // Fixed toman off the plan price.
        $this->assertSame([70000, 100000], FlashSaleAdminService::priceWithSale(100000, [
            'discount_type' => 'fixed', 'discount_value' => 30000,
        ]));

        // A discount larger than the plan floors at zero, never negative.
        $this->assertSame([0, 100000], FlashSaleAdminService::priceWithSale(100000, [
            'discount_type' => 'fixed', 'discount_value' => 200000,
        ]));
    }

    // -----------------------------------------------------------------
    // The active map
    // -----------------------------------------------------------------

    public function test_the_active_map_returns_only_live_sales(): void
    {
        $base = [
            'discount_type' => 'percent',
            'discount_value' => 15,
        ];

        // Live: started an hour ago, ends in an hour.
        FlashSale::query()->create(array_merge($base, [
            'plan_id' => 'plan-live',
            'plan_title' => 'زنده',
            'starts_at' => now('Asia/Tehran')->subHour()->format('Y-m-d H:i:s'),
            'ends_at' => now('Asia/Tehran')->addHour()->format('Y-m-d H:i:s'),
            'is_active' => true,
        ]));

        // Scheduled: starts tomorrow.
        FlashSale::query()->create(array_merge($base, [
            'plan_id' => 'plan-future',
            'plan_title' => 'آینده',
            'starts_at' => now('Asia/Tehran')->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now('Asia/Tehran')->addDays(2)->format('Y-m-d H:i:s'),
            'is_active' => true,
        ]));

        // Toggled off while inside its window.
        FlashSale::query()->create(array_merge($base, [
            'plan_id' => 'plan-off',
            'plan_title' => 'غیرفعال',
            'starts_at' => now('Asia/Tehran')->subHour()->format('Y-m-d H:i:s'),
            'ends_at' => now('Asia/Tehran')->addHour()->format('Y-m-d H:i:s'),
            'is_active' => false,
        ]));

        $map = FlashSaleAdminService::activeMap();

        $this->assertSame(['plan-live'], array_keys($map));
        $this->assertSame(15, (int) $map['plan-live']['discount_value']);
    }

    // -----------------------------------------------------------------
    // Role gating
    // -----------------------------------------------------------------

    public function test_an_editor_can_read_the_page_but_not_create_a_sale(): void
    {
        $this->postingAs(AdminRole::Editor)
            ->get(route('admin.flash-sales.index'))
            ->assertOk();

        $this->postingAs(AdminRole::Editor)
            ->post(route('admin.flash-sales.store'), [
                'action' => 'create',
                'plan_id' => 'plan-x',
                'discount_type' => 'percent',
                'discount_value' => 20,
                'starts_at' => '2026-10-20T10:00',
                'ends_at' => '2026-10-21T10:00',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('flash_sales', 0);
    }
}
