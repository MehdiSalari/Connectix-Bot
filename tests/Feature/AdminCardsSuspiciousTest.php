<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Http\Controllers\Admin\AdminCardController;
use App\Models\Admin;
use App\Models\PanelSetting;
use App\Models\PaymentCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * W3.12 — the suspicion list above the suspicious-cards page.
 *
 * The section reports the cards used more than the `card_track_threshold`
 * panel_settings row over the last seven days, masks each card to its last
 * four digits, and saves its threshold through the page's own GET — admin
 * only, like the multi-card threshold below it.
 *
 * The pinned AdminCardsTest covers the page it sits on top of; this class
 * covers only what W3.12 adds.
 */
class AdminCardsSuspiciousTest extends TestCase
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

    private function swipe(string $chatId, string $card, int $daysAgo = 0): void
    {
        PaymentCard::query()->create([
            'chat_id' => $chatId,
            'card' => $card,
            'source' => 'buy',
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    // -----------------------------------------------------------------
    // Saving the threshold
    // -----------------------------------------------------------------

    public function test_the_threshold_form_persists_the_panel_setting_and_flashes(): void
    {
        $this->postingAs()
            ->from(route('admin.cards.index'))
            ->get(route('admin.cards.index', ['threshold' => '7']))
            ->assertRedirect(route('admin.cards.index'))
            ->assertSessionHas('success', 'آستانه مشکوکسازی ذخیره شد.');

        $this->assertDatabaseHas('panel_settings', [
            'setting_key' => AdminCardController::TRACK_THRESHOLD_KEY,
            'setting_value' => '7',
        ]);

        // The saved value comes back into the form.
        $this->postingAs()
            ->get(route('admin.cards.index'))
            ->assertOk()
            ->assertSee('id="card-track-threshold" name="threshold" min="1"', false)
            ->assertSee('value="7"', false);
    }

    public function test_an_invalid_threshold_is_refused_and_stores_nothing(): void
    {
        $this->postingAs()
            ->get(route('admin.cards.index', ['threshold' => '0']))
            ->assertSessionHasErrors('threshold');

        $this->assertDatabaseMissing('panel_settings', [
            'setting_key' => AdminCardController::TRACK_THRESHOLD_KEY,
        ]);
    }

    public function test_an_editor_can_read_the_section_but_not_change_it(): void
    {
        $this->postingAs(AdminRole::Editor)
            ->get(route('admin.cards.index'))
            ->assertOk()
            ->assertSee('آستانهٔ مشکوکسازی', false);

        $this->postingAs(AdminRole::Editor)
            ->get(route('admin.cards.index', ['threshold' => '9']))
            ->assertForbidden();

        $this->assertDatabaseMissing('panel_settings', [
            'setting_key' => AdminCardController::TRACK_THRESHOLD_KEY,
        ]);
    }

    // -----------------------------------------------------------------
    // The list
    // -----------------------------------------------------------------

    public function test_cards_above_the_threshold_are_listed_masked_and_ordered_by_count(): void
    {
        // Five swipes of one card, two of another: only the first crosses the
        // default threshold of three, and it comes first by count.
        foreach (['553', '554', '555', '556', '557'] as $chatId) {
            $this->swipe($chatId, '6037991111111111');
        }
        $this->swipe('553', '6037992222222222');
        $this->swipe('553', '6037992222222222');

        $response = $this->postingAs()
            ->get(route('admin.cards.index'))
            ->assertOk()
            // Masked to the last four digits; the full number never reaches
            // the page.
            ->assertSee('****1111', false)
            ->assertDontSee('6037991111111111', false)
            ->assertDontSee('6037992222222222', false)
            ->assertSee(now()->format('Y-m-d H:i'), false);

        $rows = $response->viewData('suspicious');

        // Masked like the bot reporter's maskCard(): one star per digit
        // before the last four.
        $this->assertCount(1, $rows);
        $this->assertSame(str_repeat('*', 12).'1111', $rows[0]['card']);
        $this->assertSame('****1111', substr($rows[0]['card'], -8));
        $this->assertSame(5, $rows[0]['count']);
        $this->assertSame('553, 554, 555, 556, 557', $rows[0]['chat_id']);
        $this->assertNotNull($rows[0]['last_seen']);
    }

    public function test_a_stored_threshold_is_read_from_the_panel_setting(): void
    {
        PanelSetting::query()->create([
            'setting_key' => AdminCardController::TRACK_THRESHOLD_KEY,
            'setting_value' => '1',
        ]);

        $this->swipe('553', '6037993333333333');
        $this->swipe('554', '6037993333333333');

        $response = $this->postingAs()
            ->get(route('admin.cards.index'))
            ->assertOk();

        $this->assertSame(1, $response->viewData('trackThreshold'));
        $this->assertCount(1, $response->viewData('suspicious'));
    }

    public function test_an_empty_threshold_falls_back_to_the_default_three(): void
    {
        // Three uses do not exceed three.
        foreach (['553', '554', '555'] as $chatId) {
            $this->swipe($chatId, '6037994444444444');
        }

        $response = $this->postingAs()
            ->get(route('admin.cards.index'))
            ->assertOk();

        $this->assertSame(AdminCardController::TRACK_THRESHOLD_DEFAULT, $response->viewData('trackThreshold'));
        $this->assertSame([], $response->viewData('suspicious'));

        // The fourth use does.
        $this->swipe('556', '6037994444444444');

        $this->assertCount(
            1,
            $this->postingAs()->get(route('admin.cards.index'))->viewData('suspicious'),
        );
    }

    public function test_the_list_only_looks_at_the_last_seven_days(): void
    {
        foreach (['553', '554', '555', '556'] as $chatId) {
            $this->swipe($chatId, '6037995555555555', 8);
        }

        $this->assertSame(
            [],
            $this->postingAs()->get(route('admin.cards.index'))->viewData('suspicious'),
        );
    }

    // -----------------------------------------------------------------
    // The page around it
    // -----------------------------------------------------------------

    public function test_the_new_card_sits_above_the_existing_sections_of_the_page(): void
    {
        $html = $this->postingAs()
            ->get(route('admin.cards.index'))
            ->assertOk()
            // The fork's own sections are still there, untouched: the stats
            // card, the multi-card form and its heading, the shared-cards
            // table. (Asserted on attributes and short headings whose bytes
            // are stable, since the Persian copy carries zero-width joiners.)
            ->assertSee('id="card-threshold"', false)
            ->assertSee('name="threshold" min="2"', false)
            ->assertSee('کاربران با')
            ->assertSee('/admin/cards/threshold', false)
            ->getContent();

        $mine = strpos($html, 'کارتهای مشکوک (۷ روز اخیر)');
        $multiCard = strpos($html, 'id="card-threshold"');

        $this->assertNotFalse($mine, 'the suspicion section is missing');
        $this->assertNotFalse($multiCard, 'the multi-card section is missing');
        $this->assertLessThan($multiCard, $mine, 'the suspicion section sits above the multi-card one');

        // And an empty table still renders a full page.
        $this->assertStringContainsString('کارت مشکوکی ثبت نشده است.', $html);
    }
}
