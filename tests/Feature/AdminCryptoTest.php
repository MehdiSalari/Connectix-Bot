<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Services\Crypto\CryptoConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The crypto payment settings: the panel_settings roundtrip, the per-network
 * address validation, and the auto-confirm flag.
 */
class AdminCryptoTest extends TestCase
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
            'crypto_auto_confirm' => '1',
            'crypto_expiry_minutes' => '45',
            'crypto_min_toman' => '20000',
            'crypto_nobitex_base' => 'https://api.nobitex.ir',
            'networks' => [
                'trx' => ['enabled' => '1', 'address' => 'TXkRjzP7cJqYvHZbMsUqWDPxL9AneV5KtB', 'rate_mode' => 'manual', 'rate_toman' => '250000'],
                'ton' => ['enabled' => '0', 'address' => '', 'rate_mode' => 'manual', 'rate_toman' => '0'],
            ],
        ], $overrides);
    }

    public function test_the_settings_page_renders_the_defaults(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.crypto.index'))
            ->assertOk()
            ->assertSee('پرداخت کریپتو')
            ->assertSee('تایید خودکار')
            ->assertSee('TRX')
            ->assertSee('T…', false);

        $settings = app(CryptoConfigService::class)->all();

        $this->assertFalse($settings['crypto_enabled']);
        $this->assertTrue($settings['crypto_auto_confirm']);
        $this->assertSame(30, (int) $settings['crypto_expiry_minutes']);
    }

    public function test_a_settings_save_roundtrips_through_panel_settings(): void
    {
        $address = 'TXkRjzP7cJqYvHZbMsUqWDPxL9AneV5KtB';

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.crypto.update'), $this->payload([
                'crypto_enabled' => '1',
                'networks' => [
                    'trx' => ['enabled' => '1', 'address' => $address, 'rate_mode' => 'manual', 'rate_toman' => '250000'],
                    'ton' => ['enabled' => '0', 'address' => '', 'rate_mode' => 'manual', 'rate_toman' => '0'],
                ],
            ]))
            ->assertRedirect(route('admin.crypto.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'crypto_enabled', 'setting_value' => '1']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'crypto_auto_confirm', 'setting_value' => '1']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'crypto_expiry_minutes', 'setting_value' => '45']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'crypto_min_toman', 'setting_value' => '20000']);

        $networks = json_decode(
            (string) DB::table('panel_settings')->where('setting_key', 'crypto_networks')->value('setting_value'),
            true,
        );

        $this->assertSame($address, $networks['trx']['address']);
        $this->assertSame(250000.0, (float) $networks['trx']['rate_toman']);
        $this->assertFalse((bool) $networks['ton']['enabled']);

        // And it reads back typed, the way the bot flow will.
        $service = app(CryptoConfigService::class);

        $this->assertTrue($service->get('crypto_enabled'));
        $this->assertSame(45, $service->get('crypto_expiry_minutes'));
        $this->assertSame($address, $service->networks()['trx']['address']);
    }

    public function test_auto_confirm_persists_in_both_states(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crypto.update'), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'crypto_auto_confirm', 'setting_value' => '1']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crypto.update'), $this->payload(['crypto_auto_confirm' => null]))
            ->assertRedirect();

        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'crypto_auto_confirm', 'setting_value' => '0']);
    }

    public function test_an_invalid_trx_address_is_refused(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.crypto.update'), $this->payload([
                'networks' => [
                    'trx' => ['enabled' => '1', 'address' => 'not-a-tron-address', 'rate_mode' => 'manual', 'rate_toman' => '0'],
                    'ton' => ['enabled' => '0', 'address' => '', 'rate_mode' => 'manual', 'rate_toman' => '0'],
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['trx_address']);

        $this->assertDatabaseMissing('panel_settings', ['setting_key' => 'crypto_networks']);
    }

    public function test_an_invalid_ton_address_is_refused_and_a_valid_one_saved(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.crypto.update'), $this->payload([
                'networks' => [
                    'trx' => ['enabled' => '0', 'address' => '', 'rate_mode' => 'manual', 'rate_toman' => '0'],
                    'ton' => ['enabled' => '1', 'address' => 'not-a-ton-address', 'rate_mode' => 'manual', 'rate_toman' => '0'],
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['ton_address']);

        $valid = 'UQBk4LINI4sNazq9vup9uJqHcD8kNtzLGcCRQsw8EwepPnfk';

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.crypto.update'), $this->payload([
                'networks' => [
                    'trx' => ['enabled' => '0', 'address' => '', 'rate_mode' => 'manual', 'rate_toman' => '0'],
                    'ton' => ['enabled' => '1', 'address' => $valid, 'rate_mode' => 'manual', 'rate_toman' => '0'],
                ],
            ]))
            ->assertRedirect(route('admin.crypto.index'))
            ->assertSessionHas('success');

        $networks = json_decode(
            (string) DB::table('panel_settings')->where('setting_key', 'crypto_networks')->value('setting_value'),
            true,
        );

        $this->assertSame($valid, $networks['ton']['address']);
    }

    public function test_enabling_a_network_without_an_address_is_refused(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.crypto.update'), $this->payload([
                'networks' => [
                    'trx' => ['enabled' => '1', 'address' => '', 'rate_mode' => 'manual', 'rate_toman' => '0'],
                    'ton' => ['enabled' => '0', 'address' => '', 'rate_mode' => 'manual', 'rate_toman' => '0'],
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['trx_address']);
    }

    public function test_enabling_the_feature_with_no_network_is_refused(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.crypto.update'), $this->payload([
                'crypto_enabled' => '1',
                'networks' => [
                    'trx' => ['enabled' => '0', 'address' => '', 'rate_mode' => 'manual', 'rate_toman' => '0'],
                    'ton' => ['enabled' => '0', 'address' => '', 'rate_mode' => 'manual', 'rate_toman' => '0'],
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['crypto_enabled']);
    }

    public function test_an_editor_can_read_but_not_mutate(): void
    {
        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->get(route('admin.crypto.index'))
            ->assertOk();

        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->post(route('admin.crypto.update'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseMissing('panel_settings', ['setting_key' => 'crypto_networks']);
    }
}
