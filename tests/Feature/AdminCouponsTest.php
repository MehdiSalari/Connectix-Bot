<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Services\Coupons\LocalCouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The local discount codes: the table + form page, the case-insensitive
 * lookup, the usage counter, and the duplicate-code rule.
 */
class AdminCouponsTest extends TestCase
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

    private function storedCoupons(): array
    {
        $raw = (string) DB::table('panel_settings')->where('setting_key', 'local_coupons')->value('setting_value');

        return json_decode($raw, true) ?: [];
    }

    public function test_a_coupon_is_added_to_the_local_store(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.coupons.store'), [
                'code' => 'WELCOME20',
                'kind' => 'percent',
                'value' => '20',
                'max_uses' => '100',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.coupons.index'))
            ->assertSessionHas('success');

        $coupons = $this->storedCoupons();

        $this->assertCount(1, $coupons);
        $this->assertSame('WELCOME20', $coupons[0]['code']);
        $this->assertSame('percent', $coupons[0]['kind']);
        $this->assertSame(20, (int) $coupons[0]['value']);
        $this->assertSame(100, (int) $coupons[0]['max_uses']);
        $this->assertSame(0, (int) $coupons[0]['used_count']);
        $this->assertTrue((bool) $coupons[0]['is_active']);
    }

    public function test_the_page_lists_saved_coupons_and_loads_one_for_editing(): void
    {
        $service = app(LocalCouponService::class);
        $service->add(['code' => 'WELCOME20', 'kind' => 'percent', 'value' => 20, 'is_active' => true]);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.coupons.index'))
            ->assertOk()
            ->assertSee('کد تخفیف')
            ->assertSee('WELCOME20')
            ->assertSee('20٪');

        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.coupons.index', ['edit' => 'welcome20']))
            ->assertOk()
            ->assertSee('value="WELCOME20"', false);
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.coupons.store'), ['code' => 'WELCOME20', 'kind' => 'percent', 'value' => '20'])
            ->assertRedirect();

        // Same code, any case: refused.
        $this->actingAs($admin, 'admin')
            ->post(route('admin.coupons.store'), ['code' => 'welcome20', 'kind' => 'percent', 'value' => '50'])
            ->assertRedirect()
            ->assertSessionHasErrors(['code']);

        $this->assertCount(1, $this->storedCoupons());
    }

    public function test_the_lookup_is_case_insensitive(): void
    {
        app(LocalCouponService::class)->add([
            'code' => 'WELCOME20', 'kind' => 'percent', 'value' => 20, 'is_active' => true,
        ]);

        $this->assertNotNull(app(LocalCouponService::class)->find('welcome20'));
        $this->assertNotNull(app(LocalCouponService::class)->find('WeLcOmE20'));
        $this->assertNull(app(LocalCouponService::class)->find('OTHER'));
    }

    public function test_mark_used_increments_the_usage_count(): void
    {
        $service = app(LocalCouponService::class);
        $service->add(['code' => 'WELCOME20', 'kind' => 'percent', 'value' => 20, 'is_active' => true]);

        $service->markUsed('welcome20');
        $service->markUsed('WELCOME20');

        $coupon = $service->find('welcome20');

        $this->assertSame(2, (int) $coupon['used_count']);

        // Unknown codes are a no-op, not an error.
        $service->markUsed('NOPE');

        $this->assertSame(2, (int) $service->find('WELCOME20')['used_count']);
    }

    public function test_the_toggle_flips_the_active_flag(): void
    {
        app(LocalCouponService::class)->add([
            'code' => 'WELCOME20', 'kind' => 'percent', 'value' => 20, 'is_active' => true,
        ]);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.coupons.toggle'), ['code' => 'welcome20', 'on' => '0'])
            ->assertRedirect();

        $this->assertFalse((bool) app(LocalCouponService::class)->find('WELCOME20')['is_active']);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.coupons.toggle'), ['code' => 'WELCOME20', 'on' => '1'])
            ->assertRedirect();

        $this->assertTrue((bool) app(LocalCouponService::class)->find('welcome20')['is_active']);
    }

    public function test_an_edit_updates_the_coupon_in_place(): void
    {
        $service = app(LocalCouponService::class);
        $service->add(['code' => 'WELCOME20', 'kind' => 'percent', 'value' => 20, 'is_active' => true]);
        $service->markUsed('WELCOME20');

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.coupons.store'), [
                'original_code' => 'welcome20',
                'code' => 'WELCOME20',
                'kind' => 'fixed',
                'value' => '50000',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $coupons = $this->storedCoupons();

        $this->assertCount(1, $coupons);
        $this->assertSame('fixed', $coupons[0]['kind']);
        $this->assertSame(50000, (int) $coupons[0]['value']);
        // The usage count survived the edit.
        $this->assertSame(1, (int) $coupons[0]['used_count']);
    }

    public function test_a_coupon_is_deleted(): void
    {
        app(LocalCouponService::class)->add(['code' => 'WELCOME20', 'kind' => 'percent', 'value' => 20]);
        app(LocalCouponService::class)->add(['code' => 'OTHER10', 'kind' => 'fixed', 'value' => 10000]);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->from(route('admin.coupons.index'))
            ->post(route('admin.coupons.destroy'), ['code' => 'welcome20'])
            ->assertRedirect(route('admin.coupons.index'))
            ->assertSessionHas('success');

        $remaining = $this->storedCoupons();

        $this->assertCount(1, $remaining);
        $this->assertSame('OTHER10', $remaining[0]['code']);
    }

    public function test_a_bad_percent_is_refused(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.coupons.store'), ['code' => 'BIG150', 'kind' => 'percent', 'value' => '150'])
            ->assertRedirect()
            ->assertSessionHasErrors(['value']);

        $this->assertCount(0, $this->storedCoupons());
    }

    public function test_an_editor_can_read_but_not_mutate(): void
    {
        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->get(route('admin.coupons.index'))
            ->assertOk();

        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->post(route('admin.coupons.store'), ['code' => 'WELCOME20', 'kind' => 'percent', 'value' => '20'])
            ->assertForbidden();

        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->post(route('admin.coupons.destroy'), ['code' => 'WELCOME20'])
            ->assertForbidden();

        $this->assertDatabaseMissing('panel_settings', ['setting_key' => 'local_coupons']);
    }
}
