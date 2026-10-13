<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The online payment gateway configuration page: upsert per driver, the
 * required-field rule on activation, and the role gate.
 */
class AdminGatewaysTest extends TestCase
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

    public function test_the_page_lists_the_supported_gateways_after_seeding(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.gateways.index'))
            ->assertOk()
            ->assertSee('درگاه‌های پرداخت')
            ->assertSee('زرین‌پال')
            ->assertSee('نکست‌پی')
            ->assertSee('NOWPayments');

        // The three driver rows were seeded inactive.
        foreach (['zarinpal', 'nextpay', 'nowpayments'] as $name) {
            $this->assertDatabaseHas('payment_gateways', ['gateway_name' => $name, 'is_active' => '0']);
        }
    }

    public function test_saving_creates_the_gateway_row_keyed_by_gateway_name(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.gateways.update'), [
                'gateway_name' => 'zarinpal',
                'merchant_id' => 'abcd1234efgh5678ijkl9012mnop3456',
                'min_amount' => 50000,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.gateways.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('payment_gateways', [
            'gateway_name' => 'zarinpal',
            'merchant_id' => 'abcd1234efgh5678ijkl9012mnop3456',
            'min_amount' => '50000',
            'is_active' => '1',
        ]);
    }

    public function test_saving_again_updates_the_same_row_not_a_second_one(): void
    {
        $admin = $this->makeAdmin();

        $payload = [
            'gateway_name' => 'nextpay',
            'api_key' => 'key-one',
            'is_active' => '1',
        ];

        $this->actingAs($admin, 'admin')->post(route('admin.gateways.update'), $payload)->assertRedirect();
        $this->actingAs($admin, 'admin')->post(route('admin.gateways.update'), array_merge($payload, [
            'api_key' => 'key-two',
        ]))->assertRedirect();

        $this->assertSame(1, PaymentGateway::query()->where('gateway_name', 'nextpay')->count());
        $this->assertDatabaseHas('payment_gateways', ['gateway_name' => 'nextpay', 'api_key' => 'key-two']);
    }

    public function test_activating_without_the_driver_key_is_refused(): void
    {
        // Zarinpal needs a merchant id, nextpay an api key.
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.gateways.update'), [
                'gateway_name' => 'zarinpal',
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['merchant_id']);

        $this->assertDatabaseHas('payment_gateways', ['gateway_name' => 'zarinpal', 'is_active' => '0']);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.gateways.update'), [
                'gateway_name' => 'nextpay',
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['api_key']);

        $this->assertDatabaseHas('payment_gateways', ['gateway_name' => 'nextpay', 'is_active' => '0']);
    }

    public function test_nowpayments_needs_the_usd_rate_to_activate(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.gateways.update'), [
                'gateway_name' => 'nowpayments',
                'api_key' => 'np-key',
                'usd_rate_toman' => 0,
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['usd_rate_toman']);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.gateways.update'), [
                'gateway_name' => 'nowpayments',
                'api_key' => 'np-key',
                'usd_rate_toman' => 98000,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.gateways.index'))
            ->assertSessionHas('success');

        $row = PaymentGateway::query()->where('gateway_name', 'nowpayments')->firstOrFail();

        $this->assertTrue((bool) $row->is_active);
        $this->assertSame(98000, (int) (json_decode((string) $row->config_json, true)['usd_rate_toman'] ?? 0));
    }

    public function test_the_inline_toggle_flips_the_active_flag(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.gateways.update'), [
                'gateway_name' => 'zarinpal',
                'merchant_id' => 'abcd1234efgh5678ijkl9012mnop3456',
            ])
            ->assertRedirect();

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.gateways.toggle'), ['gateway_name' => 'zarinpal', 'active' => '1'])
            ->assertRedirect();

        $this->assertDatabaseHas('payment_gateways', ['gateway_name' => 'zarinpal', 'is_active' => '1']);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.gateways.toggle'), ['gateway_name' => 'zarinpal', 'active' => '0'])
            ->assertRedirect();

        $this->assertDatabaseHas('payment_gateways', ['gateway_name' => 'zarinpal', 'is_active' => '0']);
    }

    public function test_an_editor_can_read_but_not_mutate(): void
    {
        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->get(route('admin.gateways.index'))
            ->assertOk();

        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->post(route('admin.gateways.update'), [
                'gateway_name' => 'zarinpal',
                'merchant_id' => 'x',
                'is_active' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->post(route('admin.gateways.toggle'), ['gateway_name' => 'zarinpal', 'active' => '1'])
            ->assertForbidden();

        $this->assertSame(0, PaymentGateway::query()->where('is_active', true)->count());
    }
}
