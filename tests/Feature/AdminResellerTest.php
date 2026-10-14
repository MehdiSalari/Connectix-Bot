<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Reseller;
use App\Models\ResellerRequest;
use App\Models\User;
use App\Services\Resellers\ResellerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * نمایندگی‌ها: the reseller management page.
 *
 * The settings form must persist its panel_settings rows, the quota row form
 * must set/unlimit/clear the free-test quota, approving a request must
 * activate the representative inside a transaction, and every mutating post
 * must stay admin-only.
 */
class AdminResellerTest extends TestCase
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

        // The approve/reject decision tells the applicant; keep the bot call
        // inside the machine.
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

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

    private function makeUser(string $chatId = '553', ?string $name = 'Ali'): User
    {
        return User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => 'tg_'.$chatId,
            'name' => $name,
            'created_at' => now(),
        ]);
    }

    private function makeReseller(array $overrides = []): Reseller
    {
        return Reseller::query()->create(array_merge([
            'user_id' => null,
            'chat_id' => '553',
            'ref_code' => 'rep_'.uniqid(),
            'status' => 'ACTIVE',
            'commission_rate' => '0',
            'balance' => '0',
            'total_earned' => '0',
            'test_used' => 0,
        ], $overrides));
    }

    private function makeRequest(array $overrides = []): ResellerRequest
    {
        return ResellerRequest::query()->create(array_merge([
            'chat_id' => '553',
            'user_id' => null,
            'wallet_balance_at_request' => '50000',
            'status' => 'PENDING',
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function test_the_page_renders_with_its_sections(): void
    {
        $this->makeUser();
        $this->makeReseller();
        $this->makeRequest();

        $this->postingAs()
            ->get(route('admin.resellers.index'))
            ->assertOk()
            ->assertSee('نمایندگی‌ها')
            ->assertSee('درخواست‌های در انتظار')
            ->assertSee('سهمیهٔ تست رایگان')
            ->assertSee('صفر کردن مصرف')
            ->assertSee('553');
    }

    public function test_saving_settings_persists_the_panel_setting_rows(): void
    {
        $this->postingAs()
            ->post(route('admin.resellers.settings'), [
                'reseller_min_wallet_balance' => '100000',
                'reseller_default_test_limit' => '5',
                'reseller_default_test_unlimited' => '1',
                'reseller_monthly_quota' => '1',
                'reseller_default_discount_type' => 'fixed',
                'reseller_default_discount_value' => '20000',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'reseller_min_wallet_balance', 'setting_value' => '100000']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'reseller_default_test_limit', 'setting_value' => '5']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'reseller_default_test_unlimited', 'setting_value' => '1']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'reseller_monthly_quota', 'setting_value' => '1']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'reseller_default_discount_type', 'setting_value' => 'fixed']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'reseller_default_discount_value', 'setting_value' => '20000']);

        $service = app(ResellerService::class);
        $settings = $service->settings();

        $this->assertSame(100000.0, $settings['reseller_min_wallet_balance']);
        $this->assertTrue($settings['reseller_default_test_unlimited']);
        $this->assertTrue($settings['reseller_monthly_quota']);
        // Unlimited wins over the number when the default is read back.
        $this->assertSame(-1, $service->effectiveTestLimit(new Reseller(['test_limit' => null])));
    }

    public function test_settings_validation_rejects_a_negative_balance_and_an_unknown_discount_type(): void
    {
        $this->postingAs()
            ->post(route('admin.resellers.settings'), [
                'reseller_min_wallet_balance' => '-5',
                'reseller_default_test_limit' => '1',
                'reseller_default_discount_type' => 'percent',
                'reseller_default_discount_value' => '0',
            ])
            ->assertSessionHasErrors('reseller_min_wallet_balance');

        $this->postingAs()
            ->post(route('admin.resellers.settings'), [
                'reseller_min_wallet_balance' => '0',
                'reseller_default_test_limit' => '1',
                'reseller_default_discount_type' => 'bogus',
                'reseller_default_discount_value' => '0',
            ])
            ->assertSessionHasErrors('reseller_default_discount_type');
    }

    // -----------------------------------------------------------------
    // Quota
    // -----------------------------------------------------------------

    public function test_a_quota_can_be_set_and_cleared(): void
    {
        $reseller = $this->makeReseller(['test_used' => 3]);

        $this->postingAs()
            ->post(route('admin.resellers.quota'), [
                'reseller' => $reseller->id,
                'test_limit' => '7',
                'reset_used' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $reseller->refresh();
        $this->assertSame(7, $reseller->test_limit);
        $this->assertSame(0, $reseller->test_used);
    }

    public function test_an_unlimited_quota_stores_minus_one(): void
    {
        $reseller = $this->makeReseller();

        $this->postingAs()
            ->post(route('admin.resellers.quota'), [
                'reseller' => $reseller->id,
                'unlimited' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $reseller->refresh();
        $this->assertSame(-1, $reseller->test_limit);

        $this->postingAs()
            ->get(route('admin.resellers.index'))
            ->assertOk()
            ->assertSee('نامحدود');
    }

    public function test_an_empty_limit_returns_the_reseller_to_the_default(): void
    {
        $reseller = $this->makeReseller(['test_limit' => 5]);

        $this->postingAs()
            ->post(route('admin.resellers.quota'), [
                'reseller' => $reseller->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $reseller->refresh();
        $this->assertNull($reseller->test_limit);
    }

    public function test_the_used_counter_can_be_reset_on_its_own(): void
    {
        $reseller = $this->makeReseller(['test_used' => 9]);

        $this->postingAs()
            ->post(route('admin.resellers.reset-used'), ['reseller' => $reseller->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, $reseller->refresh()->test_used);
    }

    // -----------------------------------------------------------------
    // Discount
    // -----------------------------------------------------------------

    public function test_a_discount_is_saved_and_read_back(): void
    {
        $reseller = $this->makeReseller();

        $this->postingAs()
            ->post(route('admin.resellers.discount'), [
                'reseller' => $reseller->id,
                'type' => 'fixed',
                'value' => '15000',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $reseller->refresh();

        $discount = app(ResellerService::class)->discountFor($reseller);
        $this->assertSame('fixed', $discount['type']);
        $this->assertSame(15000.0, $discount['value']);
        $this->assertTrue($discount['custom']);
    }

    public function test_discount_validation_rejects_an_unknown_type_and_a_negative_value(): void
    {
        $reseller = $this->makeReseller();

        $this->postingAs()
            ->post(route('admin.resellers.discount'), [
                'reseller' => $reseller->id,
                'type' => 'half',
                'value' => '10',
            ])
            ->assertSessionHasErrors('type');

        $this->postingAs()
            ->post(route('admin.resellers.discount'), [
                'reseller' => $reseller->id,
                'type' => 'percent',
                'value' => '-3',
            ])
            ->assertSessionHasErrors('value');
    }

    // -----------------------------------------------------------------
    // Requests
    // -----------------------------------------------------------------

    public function test_approving_a_request_activates_the_reseller(): void
    {
        $this->makeUser('777', 'Reza');
        $request = $this->makeRequest(['chat_id' => '777']);

        $this->postingAs()
            ->post(route('admin.resellers.decide'), [
                'request_id' => $request->id,
                'action' => 'approve',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('reseller_requests', ['id' => $request->id, 'status' => 'APPROVED']);
        $this->assertDatabaseHas('resellers', ['chat_id' => '777', 'status' => 'ACTIVE']);
    }

    public function test_approving_a_second_request_reuses_the_existing_row(): void
    {
        $this->makeUser('777', 'Reza');
        $existing = $this->makeReseller(['chat_id' => '777', 'status' => 'REVOKED', 'test_limit' => 4]);
        $request = $this->makeRequest(['chat_id' => '777']);

        $this->postingAs()
            ->post(route('admin.resellers.decide'), [
                'request_id' => $request->id,
                'action' => 'approve',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // The chat keeps its row (and its own quota) rather than gaining a
        // second one.
        $this->assertSame($existing->id, Reseller::query()->where('chat_id', '777')->value('id'));
        $this->assertSame('ACTIVE', $existing->refresh()->status);
        $this->assertSame(4, $existing->test_limit);
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $request = $this->makeRequest(['status' => 'APPROVED']);

        $this->postingAs()
            ->post(route('admin.resellers.decide'), [
                'request_id' => $request->id,
                'action' => 'approve',
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('reseller_requests', ['id' => $request->id, 'status' => 'APPROVED']);
    }

    public function test_rejecting_a_request_records_the_note(): void
    {
        $request = $this->makeRequest();

        $this->postingAs()
            ->post(route('admin.resellers.decide'), [
                'request_id' => $request->id,
                'action' => 'reject',
                'admin_note' => 'موجودی کافی نیست',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('reseller_requests', [
            'id' => $request->id,
            'status' => 'REJECTED',
            'admin_note' => 'موجودی کافی نیست',
        ]);
        $this->assertDatabaseMissing('resellers', ['chat_id' => '553', 'status' => 'ACTIVE']);
    }

    // -----------------------------------------------------------------
    // Role gating
    // -----------------------------------------------------------------

    public function test_an_editor_can_read_but_not_mutate(): void
    {
        $reseller = $this->makeReseller();
        $request = $this->makeRequest();

        $this->postingAs(AdminRole::Editor)
            ->get(route('admin.resellers.index'))
            ->assertOk();

        foreach ([
            [route('admin.resellers.settings'), [
                'reseller_min_wallet_balance' => '1',
                'reseller_default_test_limit' => '1',
                'reseller_default_discount_type' => 'percent',
                'reseller_default_discount_value' => '0',
            ]],
            [route('admin.resellers.quota'), ['reseller' => $reseller->id, 'test_limit' => '2']],
            [route('admin.resellers.reset-used'), ['reseller' => $reseller->id]],
            [route('admin.resellers.discount'), ['reseller' => $reseller->id, 'type' => 'percent', 'value' => '5']],
            [route('admin.resellers.decide'), ['request_id' => $request->id, 'action' => 'approve']],
        ] as [$url, $payload]) {
            $this->postingAs(AdminRole::Editor)
                ->post($url, $payload)
                ->assertForbidden();
        }

        // Nothing was written by any of the refused posts.
        $this->assertDatabaseMissing('panel_settings', ['setting_key' => 'reseller_min_wallet_balance']);
        $this->assertDatabaseHas('reseller_requests', ['id' => $request->id, 'status' => 'PENDING']);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.resellers.index'))->assertRedirect(route('admin.login'));
    }
}
