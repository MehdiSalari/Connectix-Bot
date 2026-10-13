<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Models\WinbackLog;
use App\Models\WinbackSent;
use App\Services\Winback\WinbackAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بازگشت مشتری — the win-back campaign page: eligibility (zero successful
 * payments, inactive longer than the threshold), the queue that startCampaign
 * fills with one QUEUED log per eligible chat, the stop that cancels them,
 * and the admin-only boundary on the POST route.
 */
class AdminWinbackTest extends TestCase
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

    private function makeUser(string $chatId, int $daysInactive): User
    {
        return User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => 'tg_'.$chatId,
            'name' => 'User '.$chatId,
            'created_at' => now()->subDays($daysInactive),
        ]);
    }

    private function makePayment(string $chatId, ?string $isPaid, string $method = 'card'): void
    {
        // Raw insert: the model casts `method` to the PaymentMethod enum,
        // which has no 'loyalty' case — and the loyalty exclusion is exactly
        // what the eligibility test needs to exercise.
        \Illuminate\Support\Facades\DB::table('payments')->insert([
            'chat_id' => $chatId,
            'client_id' => 'new',
            'plan_id' => 'plan-x',
            'price' => '120,000',
            'is_paid' => $isPaid,
            'method' => $method,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function eligibleChatIds(): array
    {
        return array_map(
            static fn (object $row): string => (string) $row->chat_id,
            WinbackAdminService::getEligibleUsers(30)
        );
    }

    // -----------------------------------------------------------------
    // Eligibility
    // -----------------------------------------------------------------

    public function test_eligibility_excludes_buyers_but_keeps_loyalty_redemptions_and_inactive_non_buyers(): void
    {
        // Inactive 40 days, never paid: eligible.
        $this->makeUser('1001', 40);
        // Inactive 40 days with a settled card payment: not eligible.
        $this->makeUser('1002', 40);
        $this->makePayment('1002', '1');
        // Inactive 40 days, only a settled LOYALTY payment: a free
        // redemption is a gift, not a purchase, so still eligible.
        $this->makeUser('1003', 40);
        $this->makePayment('1003', '1', 'loyalty');
        // Inactive 40 days but only a pending payment: eligible.
        $this->makeUser('1004', 40);
        $this->makePayment('1004', null);
        // Registered yesterday: active recently, not eligible.
        $this->makeUser('1005', 1);

        $this->assertSame(['1001', '1003', '1004'], $this->eligibleChatIds());
    }

    // -----------------------------------------------------------------
    // The campaign queue
    // -----------------------------------------------------------------

    public function test_starting_the_campaign_queues_one_log_per_eligible_chat(): void
    {
        $this->makeUser('1001', 40);
        $this->makeUser('1002', 40);
        $this->makeUser('1003', 40);
        $this->makePayment('1003', '1');

        $this->postingAs()
            ->post(route('admin.winback.store'), ['action' => 'start'])
            ->assertOk()
            ->assertSee('پیام در صف ارسال قرار گرفت');

        $logs = WinbackLog::query()->orderBy('chat_id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['1001', '1002'], $logs->pluck('chat_id')->all());
        $this->assertSame('QUEUED', $logs[0]->status);
        $this->assertSame('QUEUED', $logs[1]->status);

        // Each queued chat is claimed for this cycle, so a restart inside
        // the same window does not double-queue anyone.
        $this->assertSame(2, WinbackSent::query()->count());
        $this->assertSame([], $this->eligibleChatIds());

        $this->postingAs()
            ->post(route('admin.winback.store'), ['action' => 'start'])
            ->assertOk();

        $this->assertDatabaseCount('winback_logs', 2);
    }

    public function test_stopping_the_campaign_cancels_the_queue(): void
    {
        $this->makeUser('1001', 40);
        $this->makeUser('1002', 40);

        $this->postingAs()
            ->post(route('admin.winback.store'), ['action' => 'start'])
            ->assertOk();
        $this->assertSame(2, WinbackAdminService::getQueuedCount());

        $this->postingAs()
            ->post(route('admin.winback.store'), ['action' => 'stop'])
            ->assertOk()
            ->assertSee('2 پیام از صف ارسال خارج شد');

        $this->assertSame(0, WinbackAdminService::getQueuedCount());
        $this->assertSame(2, WinbackLog::query()->where('status', 'CANCELLED')->count());
        // The claims are released, so a restart re-queues the same chats.
        $this->assertSame(0, WinbackSent::query()->count());
        $this->assertCount(2, $this->eligibleChatIds());
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function test_the_settings_round_trip_through_the_page(): void
    {
        $this->postingAs()
            ->post(route('admin.winback.store'), [
                'action' => 'save_settings',
                'is_active' => '1',
                'inactive_days' => '45',
                'message_text' => 'برگرد {name}',
                'coupon_code' => 'BACK10',
            ])
            ->assertOk()
            ->assertSee('تنظیمات بازگشت مشتری ذخیره شد.');

        $settings = WinbackAdminService::getSettings();
        $this->assertTrue((bool) $settings['is_active']);
        $this->assertSame(45, (int) $settings['inactive_days']);
        $this->assertSame('برگرد {name}', $settings['message_text']);
        $this->assertSame('BACK10', $settings['coupon_code']);

        // The threshold is what eligibility uses: a 50-day-old user now
        // clears the 45-day bar.
        $this->makeUser('1001', 50);
        $this->assertCount(1, WinbackAdminService::getEligibleUsers((int) $settings['inactive_days']));
    }

    // -----------------------------------------------------------------
    // Role gating
    // -----------------------------------------------------------------

    public function test_an_editor_can_read_the_page_but_not_run_the_campaign(): void
    {
        $this->makeUser('1001', 40);

        $this->postingAs(AdminRole::Editor)
            ->get(route('admin.winback.index'))
            ->assertOk();

        $this->postingAs(AdminRole::Editor)
            ->post(route('admin.winback.store'), ['action' => 'start'])
            ->assertForbidden();

        $this->assertDatabaseCount('winback_logs', 0);
    }
}
