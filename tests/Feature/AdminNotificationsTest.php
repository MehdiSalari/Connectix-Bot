<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Client;
use App\Models\NotificationSetting;
use App\Services\Notifications\NotificationAdminService;
use App\Support\PersianCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * یادآور و هشدار — the notification settings page: the CSV settings
 * persisted (with non-numeric entries dropped), and the due-client preview
 * that groups the seeded clients by days-to-expiry on Tehran days, parsing
 * both the legacy Jalali expire_date strings and the newer Gregorian ones.
 */
class AdminNotificationsTest extends TestCase
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

    /** A Jalali 'Y-m-d H:i' string for $daysFromNow days ahead (Tehran). */
    private function jalaliExpiry(int $daysFromNow): string
    {
        $target = Carbon::now('Asia/Tehran')->addDays($daysFromNow);
        $j = PersianCalendar::toJalali(
            (int) $target->format('Y'),
            (int) $target->format('n'),
            (int) $target->format('j'),
        );

        return sprintf('%04d-%02d-%02d 12:00', $j['year'], $j['month'], $j['day']);
    }

    private function makeClient(string $id, string $expireDate): Client
    {
        return Client::query()->create([
            'id' => $id,
            'username' => 'user-'.$id,
            'chat_id' => '553',
            'expire_date' => $expireDate,
        ]);
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function test_saving_the_settings_persists_the_csv_columns(): void
    {
        $this->postingAs()
            ->post(route('admin.notifications.store'), [
                'action' => 'save_settings',
                'is_active' => '1',
                'expiry_days_before' => '5,2',
                'usage_percent_thresholds' => '95,70',
            ])
            ->assertOk()
            ->assertSee('تنظیمات با موفقیت ذخیره شد.');

        $row = NotificationSetting::query()->sole();
        $this->assertTrue((bool) $row->is_active);
        // The service sorts and de-duplicates the list it stores.
        $this->assertSame('2,5', $row->expiry_days_before);
        $this->assertSame('70,95', $row->usage_percent_thresholds);

        // Turning it off flips only the flag.
        $this->postingAs()
            ->post(route('admin.notifications.store'), [
                'action' => 'save_settings',
                'expiry_days_before' => '2,5',
                'usage_percent_thresholds' => '70,95',
            ])
            ->assertOk();

        $this->assertFalse((bool) NotificationSetting::query()->sole()->is_active);
    }

    public function test_non_numeric_csv_entries_are_dropped_not_stored(): void
    {
        $this->postingAs()
            ->post(route('admin.notifications.store'), [
                'action' => 'save_settings',
                'is_active' => '1',
                // Two numbers, one word, one zero: only the numbers survive.
                'expiry_days_before' => '3,abc,0',
                'usage_percent_thresholds' => 'foo,bar',
            ])
            ->assertOk();

        $row = NotificationSetting::query()->sole();
        $this->assertSame('3', $row->expiry_days_before);
        // Nothing numeric left at all: the column falls back to '0' rather
        // than carrying the words through into the cron's day list.
        $this->assertSame('0', $row->usage_percent_thresholds);
    }

    // -----------------------------------------------------------------
    // Due clients
    // -----------------------------------------------------------------

    public function test_due_clients_are_grouped_by_days_left_on_tehran_days(): void
    {
        // Settings default to '3,1' days before expiry.
        $this->makeClient('uuid-3d', $this->jalaliExpiry(3));
        $this->makeClient('uuid-1d', $this->jalaliExpiry(1));
        // Outside the window: 10 days is beyond the largest configured day.
        $this->makeClient('uuid-10d', $this->jalaliExpiry(10));
        // Unparseable junk is skipped, never grouped.
        $this->makeClient('uuid-bad', 'not-a-date');

        $due = NotificationAdminService::dueClients(Carbon::now('Asia/Tehran'));

        $this->assertSame([1, 3], array_keys($due));
        $this->assertSame(['uuid-1d'], array_column($due[1], 'id'));
        $this->assertSame(['uuid-3d'], array_column($due[3], 'id'));
        // Only the columns the clients table actually carries.
        $this->assertSame(
            ['id', 'chat_id', 'username', 'expire_date'],
            array_keys($due[3][0])
        );
    }

    public function test_the_page_renders_the_due_client_preview(): void
    {
        $this->makeClient('uuid-soon', $this->jalaliExpiry(1));

        $this->postingAs()
            ->get(route('admin.notifications.index'))
            ->assertOk()
            // The heading joins پیش and نمایش with a ZWNJ, so assert on the
            // part both forms share.
            ->assertSee('کاربران واجد شرایط')
            ->assertSee('user-uuid-soon')
            ->assertSee('1 روز تا انقضا');
    }

    // -----------------------------------------------------------------
    // Role gating
    // -----------------------------------------------------------------

    public function test_an_editor_can_read_the_page_but_not_save(): void
    {
        $this->postingAs(AdminRole::Editor)
            ->get(route('admin.notifications.index'))
            ->assertOk();

        $this->postingAs(AdminRole::Editor)
            ->post(route('admin.notifications.store'), [
                'action' => 'save_settings',
                'is_active' => '1',
                'expiry_days_before' => '9,9',
            ])
            ->assertForbidden();

        // The page seeds the default settings row on read; the editor's
        // POST must not have replaced it.
        $this->assertDatabaseHas('notification_settings', ['expiry_days_before' => '3,1']);
    }
}
