<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\PanelSetting;
use App\Models\User;
use App\Models\WelcomeCreditLog;
use App\Services\Features\DbBackupAdminService;
use App\Services\Features\StartEmojiAdminService;
use App\Services\Features\WelcomeCreditAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ویژگیهای ویژه — the special-features page: brand settings, the /start
 * emoji, the welcome-credit eligibility policy (exists, never bought, never
 * granted) and the database backup — both the schedule settings and the
 * send-now seam that must build the dump file even when no transport is
 * configured. The backup files the tests create are cleaned up afterwards.
 */
class AdminFeaturesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> Backup files to remove after each test. */
    private array $backupFiles = [];

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

    protected function tearDown(): void
    {
        foreach ($this->backupFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
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

    private function makeUser(string $chatId = '888'): User
    {
        return User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => 'tg_'.$chatId,
            'name' => 'Ali',
            'created_at' => now(),
        ]);
    }

    private function makePayment(string $chatId, ?string $isPaid, string $method = 'card'): void
    {
        // Raw insert: `method` is cast to the PaymentMethod enum, which has
        // no 'loyalty' case the policy also cares about.
        DB::table('payments')->insert([
            'chat_id' => $chatId,
            'client_id' => 'new',
            'plan_id' => 'plan-x',
            'price' => '120,000',
            'is_paid' => $isPaid,
            'method' => $method,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    // -----------------------------------------------------------------
    // Brand
    // -----------------------------------------------------------------

    public function test_the_brand_settings_are_saved_and_shown(): void
    {
        $this->postingAs()
            ->post(route('admin.features.store'), [
                'form' => 'brand',
                'brand_name' => 'AcmeNet',
                'brand_channel_url' => 'https://t.me/acmenet',
            ])
            ->assertOk()
            ->assertSee('تنظیمات برند ذخیره شد.');

        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'brand_name', 'setting_value' => 'AcmeNet']);
        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'brand_channel_url', 'setting_value' => 'https://t.me/acmenet']);

        // The saved name comes back into the form value on the next render.
        $this->postingAs()
            ->get(route('admin.features.index'))
            ->assertOk()
            ->assertSee('value="AcmeNet"', false)
            ->assertSee('https://t.me/acmenet', false);
    }

    // -----------------------------------------------------------------
    // Start emoji
    // -----------------------------------------------------------------

    public function test_the_start_emoji_settings_round_trip(): void
    {
        $this->postingAs()
            ->post(route('admin.features.store'), [
                'form' => 'start_emoji',
                'emoji_enabled' => '1',
                'emoji_char' => '🚀',
                'custom_emoji_id' => '5341234567890123',
            ])
            ->assertOk()
            ->assertSee('تنظیمات ایموجی استارت ذخیره شد.');

        $emoji = StartEmojiAdminService::get();
        $this->assertTrue($emoji['enabled']);
        $this->assertSame('🚀', $emoji['emoji_char']);
        // Non-digits are stripped out of the premium id.
        $this->assertSame('5341234567890123', $emoji['custom_emoji_id']);
        $this->assertStringContainsString('<tg-emoji emoji-id="5341234567890123">', StartEmojiAdminService::render());

        // Disabling it flips only the flag; the char falls back to 👋 when cleared.
        $this->postingAs()
            ->post(route('admin.features.store'), [
                'form' => 'start_emoji',
                'emoji_char' => '  ',
            ])
            ->assertOk();

        $emoji = StartEmojiAdminService::get();
        $this->assertFalse($emoji['enabled']);
        $this->assertSame('👋', $emoji['emoji_char']);
    }

    // -----------------------------------------------------------------
    // Welcome credit
    // -----------------------------------------------------------------

    public function test_welcome_credit_eligibility_never_pays_twice_or_ignores_a_buyer(): void
    {
        // A fresh user with no payments is eligible.
        $this->makeUser('888');
        $this->assertTrue(WelcomeCreditAdminService::eligible('888'));

        // A settled non-loyalty payment disqualifies them.
        $buyer = $this->makeUser('889');
        $this->makePayment($buyer->chat_id, '1');
        $this->assertFalse(WelcomeCreditAdminService::eligible('889'));

        // A pending receipt does not: nothing has been paid yet.
        $this->makeUser('890');
        $this->makePayment('890', null);
        $this->assertTrue(WelcomeCreditAdminService::eligible('890'));

        // After the grant is logged, the same user is never eligible again.
        WelcomeCreditLog::query()->create(['chat_id' => '888', 'amount' => 5000]);
        $this->assertFalse(WelcomeCreditAdminService::eligible('888'));

        // Unknown chats are not eligible at all.
        $this->assertFalse(WelcomeCreditAdminService::eligible('999'));
    }

    // -----------------------------------------------------------------
    // Database backup
    // -----------------------------------------------------------------

    public function test_send_now_builds_the_dump_file_even_without_a_transport(): void
    {
        $result = DbBackupAdminService::sendNow();

        // No sender is configured, so the send fails — but the file exists.
        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
        $this->assertNotNull($result['path']);
        $this->assertFileExists($result['path']);
        $this->assertGreaterThan(0, filesize($result['path']));

        $dump = (string) file_get_contents($result['path']);
        $this->assertStringContainsString('Connectix database backup', $dump);
        $this->assertStringContainsString('Table: admins', $dump);

        // The failed attempt is remembered for the page's status card.
        $settings = DbBackupAdminService::get();
        $this->assertNotSame('', $settings['last_attempt_at']);
        $this->assertSame($result['error'], $settings['last_error']);
        $this->assertSame('', $settings['last_sent_at']);

        $this->backupFiles[] = $result['path'];
    }

    public function test_the_backup_schedule_round_trips(): void
    {
        $this->postingAs()
            ->post(route('admin.features.store'), [
                'form' => 'db_backup_schedule',
                'bk_enabled' => '1',
                'bk_frequency' => 'weekly',
                'bk_time' => '04:30',
                'bk_weekday' => '5',
                'bk_target' => 'custom',
                'bk_custom_chat_id' => '12345',
            ])
            ->assertOk()
            // The message joins زمان and بندی with a ZWNJ; assert on the
            // tail both spellings share.
            ->assertSee('ارسال خودکار بکاپ ذخیره شد.');

        $backup = DbBackupAdminService::get();
        $this->assertTrue($backup['enabled']);
        $this->assertSame('weekly', $backup['frequency']);
        $this->assertSame('04:30', $backup['time']);
        $this->assertSame(5, (int) $backup['weekday']);
        $this->assertSame('custom', $backup['target']);
        $this->assertSame('12345', $backup['custom_chat_id']);
        $this->assertSame('12345', DbBackupAdminService::targetChatId());

        // A junk time falls back to the shipped default instead of storing
        // something the cron could never parse.
        $this->postingAs()
            ->post(route('admin.features.store'), [
                'form' => 'db_backup_schedule',
                'bk_time' => 'banana',
            ])
            ->assertOk();

        $this->assertSame('03:00', DbBackupAdminService::get()['time']);
    }

    // -----------------------------------------------------------------
    // Role gating
    // -----------------------------------------------------------------

    public function test_an_editor_can_read_the_page_but_not_mutate(): void
    {
        $this->postingAs(AdminRole::Editor)
            ->get(route('admin.features.index'))
            ->assertOk();

        $this->postingAs(AdminRole::Editor)
            ->post(route('admin.features.store'), [
                'form' => 'brand',
                'brand_name' => 'Hacked',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('panel_settings', ['setting_key' => 'brand_name']);
    }
}
