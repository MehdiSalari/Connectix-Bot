<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The security contract of Phase 17 in executable form: response headers,
 * the login rate limit, hard media validation on the broadcast upload, role
 * and origin gates on the send loop, the client password field, and the
 * optional shared secret on the public bank gateway endpoint.
 */
class SecurityHardeningTest extends TestCase
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
            'connectix_bot.bank.secret' => '',
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

    // -----------------------------------------------------------------
    // Response headers
    // -----------------------------------------------------------------

    public function test_every_page_response_carries_the_hardening_headers(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'same-origin');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);

        $this->assertNotEmpty($response->headers->get('Permissions-Policy'));

        // HSTS is a TLS-only promise; an http test request must not get one.
        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    // -----------------------------------------------------------------
    // Login rate limit
    // -----------------------------------------------------------------

    public function test_login_attempts_are_rate_limited_per_email_and_ip(): void
    {
        $admin = $this->makeAdmin();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/admin/login', [
                'email' => $admin->email,
                'password' => 'wrong-'.$attempt,
            ])->assertRedirect(route('admin.login'));
        }

        // The sixth request is refused before a password is looked at - even
        // this one is the correct password.
        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'secret-pass',
        ])->assertStatus(429);
    }

    // -----------------------------------------------------------------
    // Broadcast upload
    // -----------------------------------------------------------------

    public function test_a_script_upload_is_rejected(): void
    {
        $php = UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]);');

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.broadcast.start'), [
                'message' => 'hello',
                'media' => $php,
            ])
            ->assertSessionHasErrors('media');
    }

    public function test_an_oversized_upload_is_rejected(): void
    {
        // 11MB against a ten megabyte ceiling.
        $big = UploadedFile::fake()->create('huge.mp4', 11 * 1024, 'video/mp4');

        $this->actingAs($this->makeAdmin(), 'admin')
            ->post(route('admin.broadcast.start'), [
                'message' => 'hello',
                'media' => $big,
            ])
            ->assertSessionHasErrors('media');
    }

    // -----------------------------------------------------------------
    // The send loop
    // -----------------------------------------------------------------

    public function test_an_editor_cannot_reach_the_progress_stream(): void
    {
        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->get(route('admin.broadcast.progress'))
            ->assertForbidden();
    }

    public function test_the_progress_stream_refuses_a_cross_site_request(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->withHeaders(['Sec-Fetch-Site' => 'cross-site'])
            ->get(route('admin.broadcast.progress'))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Client details
    // -----------------------------------------------------------------

    public function test_only_an_administrator_receives_a_client_password(): void
    {
        Client::query()->create([
            'id' => 'uuid-1',
            'username' => 'ali-user',
            'chat_id' => '553',
            'password' => 'hunter2',
            'count_of_devices' => 2,
        ]);

        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/show*' => Http::response([
                'client' => ['id' => 'uuid-1', 'username' => 'ali-user'],
            ], 200),
        ]);

        $this->actingAs($this->makeAdmin(AdminRole::Admin), 'admin')
            ->getJson(route('admin.clients.show', 'uuid-1'))
            ->assertOk()
            ->assertJsonPath('local.password', 'hunter2');

        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->getJson(route('admin.clients.show', 'uuid-1'))
            ->assertOk()
            ->assertJsonMissingPath('local.password');
    }

    // -----------------------------------------------------------------
    // Bank gateway
    // -----------------------------------------------------------------

    public function test_a_configured_bank_secret_is_required_in_the_header(): void
    {
        config(['connectix_bot.bank.secret' => 'shared-secret']);

        $this->postJson('/bank/sms', ['msg' => 'transfer'])
            ->assertForbidden();

        $this->withHeaders(['X-Bank-Sms-Secret' => 'wrong'])
            ->postJson('/bank/sms', ['msg' => 'transfer'])
            ->assertForbidden();

        // The right secret passes the gate: what follows is an ordinary
        // validation failure for the empty body, not a 403.
        $this->withHeaders(['X-Bank-Sms-Secret' => 'shared-secret'])
            ->postJson('/bank/sms', [])
            ->assertStatus(400);
    }

    public function test_an_unset_secret_keeps_the_legacy_gateway_working(): void
    {
        config(['connectix_bot.bank.secret' => '']);

        $this->postJson('/bank/sms', [])
            ->assertStatus(400);
    }
}
