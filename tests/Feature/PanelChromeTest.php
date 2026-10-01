<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The chrome around a panel page rather than its content: the back control a
 * detail page needs because it has no sidebar entry of its own, the placement
 * of the wallet history trigger next to its form's submit, and the shared
 * file-input markup the drop-area styling in connectix.css hangs off.
 */
class PanelChromeTest extends TestCase
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
    }

    private function actingAsAdmin()
    {
        $admin = Admin::query()->create([
            'email' => 'admin-'.uniqid().'@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => AdminRole::Admin,
        ]);

        return $this->actingAs($admin, 'admin');
    }

    public function test_a_detail_page_links_back_to_its_list(): void
    {
        $user = User::query()->create(['chat_id' => '777000111', 'name' => 'Hadi']);

        $this->actingAsAdmin()
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('href="'.route('admin.users.index').'"', false)
            ->assertSee('aria-label="بازگشت به صفحه قبل"', false);
    }

    public function test_a_page_that_is_in_the_sidebar_gets_no_back_button(): void
    {
        $this->actingAsAdmin()
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee('بازگشت به صفحه قبل', false);
    }

    public function test_the_wallet_history_trigger_sits_next_to_apply(): void
    {
        $user = User::query()->create(['chat_id' => '777000222', 'name' => 'Hadi']);

        $html = $this->actingAsAdmin()->get(route('admin.users.show', $user))->assertOk()->getContent();

        // Inside the wallet form, after «اعمال» — in RTL that lands it on the
        // submit button's left, and type="button" keeps it from submitting.
        $this->assertMatchesRegularExpression(
            '/<button type="submit">اعمال<\/button>\s*<button type="button" class="btn ghost"\s+data-wallet-history="777000222"/u',
            $html
        );
    }

    public function test_an_upload_field_renders_the_plain_file_input_the_stylesheet_targets(): void
    {
        $this->actingAsAdmin()
            ->get(route('admin.broadcast.show'))
            ->assertOk()
            ->assertSee('<input type="file" name="media">', false);
    }

    // -----------------------------------------------------------------
    // The users list
    // -----------------------------------------------------------------

    public function test_the_name_cell_is_plain_text_not_a_second_profile_link(): void
    {
        $user = User::query()->create(['chat_id' => '777000333', 'name' => 'Ebrahim8008']);

        $html = $this->actingAsAdmin()
            ->get(route('admin.users.index'))
            ->assertOk()
            // …ولی دکمه‌ی «جزئیات» هنوز به همان صفحه می‌رود.
            ->assertSee('href="'.route('admin.users.show', $user).'"', false)
            ->assertSee('>جزئیات</a>', false)
            ->getContent();

        // نامِ سطر، لینک نیست.
        $this->assertSame(0, preg_match('/<a href="[^"]*admin\/users\/\d+">Ebrahim8008<\/a>/u', $html));
    }

    public function test_the_search_form_is_wired_for_live_search(): void
    {
        $this->actingAsAdmin()
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('data-live-search', false)
            ->assertSee('data-live-input', false)
            ->assertSee('id="users-results"', false);
    }

    public function test_search_covers_the_chat_id_the_name_and_the_telegram_username(): void
    {
        User::query()->create(['chat_id' => '888000111', 'telegram_id' => 'F_bzr', 'name' => 'Ebrahim']);
        User::query()->create(['chat_id' => '888000222', 'telegram_id' => 'someone_else', 'name' => 'Zed']);

        $this->actingAsAdmin()
            ->get(route('admin.users.index', ['search' => 'F_bzr']))
            ->assertOk()
            ->assertSee('Ebrahim')
            ->assertDontSee('Zed');

        $this->actingAsAdmin()
            ->get(route('admin.users.index', ['search' => '888000111']))
            ->assertOk()
            ->assertSee('Ebrahim')
            ->assertDontSee('Zed');
    }

    // -----------------------------------------------------------------
    // The details sheet
    // -----------------------------------------------------------------

    public function test_the_sheet_profile_link_points_at_the_profile_page(): void
    {
        $user = User::query()->create(['chat_id' => '777000444', 'name' => 'Hadi']);

        $this->actingAsAdmin()
            ->getJson(route('admin.users.details', $user->chat_id))
            ->assertOk()
            ->assertJsonPath('profile_url', route('admin.users.show', $user));
    }

    public function test_the_profile_header_links_the_telegram_username(): void
    {
        $user = User::query()->create([
            'chat_id' => '777000555',
            'name' => 'Hadi',
            'telegram_id' => 'Yaabasalehalmahdy13930',
        ]);

        $this->actingAsAdmin()
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('href="https://t.me/Yaabasalehalmahdy13930"', false);

        // بدون نام کاربری، خط تیره می‌ماند و لینکی ساخته نمی‌شود.
        $plain = User::query()->create(['chat_id' => '777000666', 'name' => 'NoName']);

        $html = $this->actingAsAdmin()
            ->get(route('admin.users.show', $plain))
            ->assertOk()
            ->assertSee('تلگرام: -', false)
            ->getContent();

        $this->assertSame(0, preg_match('/<p class="muted">تلگرام: <a /u', $html));
    }
}
