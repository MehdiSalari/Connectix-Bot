<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\LocalPlan;
use App\Models\Panel;
use App\Services\Panels\PanelNodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Admin Panels page: panel CRUD, test connection (Http::fake), plans CRUD,
 * delete refuses when plans reference it. Mutations admin only; index admin,editor.
 */
class AdminPanelNodesTest extends TestCase
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
    public function test_index_renders_panels_and_plans(): void
    {
        $this->actingAsAdmin();
        Panel::query()->create([
            'name' => 'Test Marzban',
            'type' => 'marzban',
            'base_url' => 'https://panel.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('secret123'),
            'is_active' => true,
        ]);
        LocalPlan::query()->create([
            'name' => 'Gold',
            'price' => 100000,
            'days' => 30,
            'traffic_gb' => 50,
            'devices' => 2,
            'panel_id' => 1,
            'group_name' => 'default',
            'is_active' => true,
        ]);

        $response = $this->get(route('admin.panels.index'));
        $response->assertOk();
        $response->assertViewHas('panels');
        $response->assertViewHas('plans');
        $response->assertViewHas('types');
        $response->assertViewHas('groups');
    }

    /** @test */
    public function test_editor_can_see_index_but_not_mutate(): void
    {
        $this->actingAsEditor();
        $this->get(route('admin.panels.index'))->assertOk();

        $this->post(route('admin.panels.save'), [
            'name' => 'X', 'type' => 'marzban', 'base_url' => 'https://x.com',
            'username' => 'u', 'secret' => 's',
        ])->assertForbidden();
        $this->post(route('admin.panels.delete'), ['id' => 1])->assertForbidden();
        $this->post(route('admin.panels.test'), ['id' => 1])->assertForbidden();
        $this->post(route('admin.panels.plan.save'), [
            'name' => 'P', 'days' => 30, 'traffic_gb' => 10, 'devices' => 1,
            'panel_id' => 1, 'group_name' => 'default',
        ])->assertForbidden();
        $this->post(route('admin.panels.plan.delete'), ['id' => 1])->assertForbidden();
    }

    /** @test */
    public function test_save_panel_encrypts_secret(): void
    {
        $this->actingAsAdmin();
        $this->post(route('admin.panels.save'), [
            'name' => 'Enc Panel',
            'type' => 'marzban',
            'base_url' => 'https://enc.example.com',
            'username' => 'admin',
            'secret' => 'myplainsecret',
            'is_active' => true,
        ])->assertRedirect();

        $panel = Panel::query()->where('name', 'Enc Panel')->first();
        $this->assertNotNull($panel);
        $this->assertNotEquals('myplainsecret', $panel->secret);
        $this->assertEquals('myplainsecret', Crypt::decryptString($panel->secret));
    }

    /** @test */
    public function test_save_panel_keeps_existing_secret_when_empty(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'Keep Secret',
            'type' => 'marzban',
            'base_url' => 'https://keep.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('original'),
            'is_active' => true,
        ]);

        // Update without sending secret (form sends empty string)
        $this->post(route('admin.panels.save'), [
            'id' => $panel->id,
            'name' => 'Keep Secret Updated',
            'type' => 'marzban',
            'base_url' => 'https://keep.example.com',
            'username' => 'admin',
            'secret' => '', // empty = keep
            'is_active' => true,
        ])->assertRedirect();

        $panel->refresh();
        $this->assertEquals('original', Crypt::decryptString($panel->secret));
    }

    /** @test */
    public function test_delete_panel_refuses_when_plans_exist(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'Has Plans',
            'type' => 'marzban',
            'base_url' => 'https://hp.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('s'),
            'is_active' => true,
        ]);
        LocalPlan::query()->create([
            'name' => 'P1',
            'price' => 1000,
            'days' => 30,
            'traffic_gb' => 10,
            'devices' => 1,
            'panel_id' => $panel->id,
            'group_name' => 'default',
            'is_active' => true,
        ]);

        $response = $this->post(route('admin.panels.delete'), ['id' => $panel->id]);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertNotNull(Panel::query()->find($panel->id)); // still exists
    }

    /** @test */
    public function test_delete_panel_succeeds_when_no_plans(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'No Plans',
            'type' => 'marzban',
            'base_url' => 'https://np.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('s'),
            'is_active' => true,
        ]);

        $this->post(route('admin.panels.delete'), ['id' => $panel->id])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertNull(Panel::query()->find($panel->id));
    }

    /** @test */
    public function test_panel_marzban_ok_via_http_fake(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'Marzban Test',
            'type' => 'marzban',
            'base_url' => 'https://marzban.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('password123'),
            'is_active' => true,
        ]);

        Http::fake([
            'marzban.example.com/api/admin/token' => Http::response([
                'access_token' => 'fake-token-123',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
            'marzban.example.com/api/admin' => Http::response([
                'username' => 'admin',
                'is_superuser' => true,
            ], 200),
        ]);

        $svc = app(PanelNodeService::class);
        $result = $svc->testPanel($panel->id);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('اتصال برقرار شد', $result['msg']);
        Http::assertSentCount(2);
    }

    /** @test */
    public function test_panel_xui_wrong_creds_via_http_fake(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'XUI Test',
            'type' => 'xui',
            'base_url' => 'https://xui.example.com/AbCdEf123',
            'username' => 'admin',
            'secret' => Crypt::encryptString('wrongpass'),
            'is_active' => true,
            'extra' => ['inbound_id' => '1'],
        ]);

        Http::fake([
            'xui.example.com/AbCdEf123/login' => Http::response('', 401),
        ]);

        $svc = app(PanelNodeService::class);
        $result = $svc->testPanel($panel->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ناموفق', $result['msg']);
    }

    /** @test */
    public function test_plan_crud_with_is_free(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'Plan Panel',
            'type' => 'marzban',
            'base_url' => 'https://pp.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('s'),
            'is_active' => true,
        ]);

        // Create
        $this->post(route('admin.panels.plan.save'), [
            'name' => 'Free Plan',
            'price' => 0,
            'days' => 30,
            'traffic_gb' => 10,
            'devices' => 1,
            'panel_id' => $panel->id,
            'group_name' => 'default',
            'is_free' => true,
            'is_active' => true,
            'sort_order' => 0,
        ])->assertRedirect();

        $plan = LocalPlan::query()->where('name', 'Free Plan')->first();
        $this->assertNotNull($plan);
        $this->assertTrue($plan->is_free);
        $this->assertEquals(0, $plan->price);

        // Update to paid
        $this->post(route('admin.panels.plan.save'), [
            'id' => $plan->id,
            'name' => 'Paid Plan',
            'price' => 50000,
            'days' => 30,
            'traffic_gb' => 50,
            'devices' => 2,
            'panel_id' => $panel->id,
            'group_name' => 'default',
            'is_free' => false,
            'is_active' => true,
            'sort_order' => 1,
        ])->assertRedirect();

        $plan->refresh();
        $this->assertFalse($plan->is_free);
        $this->assertEquals(50000, $plan->price);

        // Delete
        $this->post(route('admin.panels.plan.delete'), ['id' => $plan->id])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertNull(LocalPlan::query()->find($plan->id));
    }

    /** @test */
    public function test_list_plans_filters_by_panel(): void
    {
        $this->actingAsAdmin();
        $p1 = Panel::query()->create(['name' => 'P1', 'type' => 'marzban', 'base_url' => 'https://p1.com', 'username' => 'a', 'secret' => Crypt::encryptString('s'), 'is_active' => true]);
        $p2 = Panel::query()->create(['name' => 'P2', 'type' => 'marzban', 'base_url' => 'https://p2.com', 'username' => 'a', 'secret' => Crypt::encryptString('s'), 'is_active' => true]);

        LocalPlan::query()->create(['name' => 'A', 'price' => 100, 'days' => 30, 'traffic_gb' => 10, 'devices' => 1, 'panel_id' => $p1->id, 'group_name' => 'default', 'is_active' => true]);
        LocalPlan::query()->create(['name' => 'B', 'price' => 200, 'days' => 30, 'traffic_gb' => 20, 'devices' => 1, 'panel_id' => $p2->id, 'group_name' => 'default', 'is_active' => true]);

        $svc = app(PanelNodeService::class);
        $all = $svc->listPlans();
        $p1Only = $svc->listPlans($p1->id);
        $p2Only = $svc->listPlans($p2->id);

        $this->assertCount(2, $all);
        $this->assertCount(1, $p1Only);
        $this->assertEquals('A', $p1Only[0]['name']);
        $this->assertCount(1, $p2Only);
        $this->assertEquals('B', $p2Only[0]['name']);
    }
}