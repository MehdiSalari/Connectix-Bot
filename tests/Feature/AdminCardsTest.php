<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\PanelSetting;
use App\Models\PaymentCard;
use App\Services\Cards\CardTrackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * کارت‌های مشکوک: the suspicious-card tracker and its page.
 *
 * A chat under the threshold stays unflagged, reaching the threshold flags
 * it, the page lists flagged chats with their cards, and the threshold form
 * persists its panel_settings row (floored at 2).
 */
class AdminCardsTest extends TestCase
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
    // The tracker
    // -----------------------------------------------------------------

    public function test_a_chat_below_the_threshold_is_not_flagged(): void
    {
        $service = app(CardTrackService::class);

        $this->assertFalse($service->record('553', '6037991111111111'));
        $this->assertFalse($service->record('553', '6037991111111111'));
        $this->assertFalse($service->record('553', '6037992222222222'));

        $this->assertSame([], $service->flaggedChats());
    }

    public function test_a_chat_at_the_threshold_is_flagged(): void
    {
        $service = app(CardTrackService::class);

        $this->assertFalse($service->record('553', '6037991111111111'));
        $this->assertFalse($service->record('553', '6037992222222222'));
        $this->assertTrue($service->record('553', '6037993333333333'), 'the third distinct card crosses the default threshold of 3');
    }

    public function test_flagged_chats_list_the_chat_with_its_cards(): void
    {
        $service = app(CardTrackService::class);

        $service->record('553', '6037991111111111');
        $service->record('553', '6037992222222222');
        $service->record('553', '6037993333333333');
        $service->record('554', '6037994444444444');

        $flagged = $service->flaggedChats();

        $this->assertCount(1, $flagged);
        $this->assertSame('553', $flagged[0]['chat_id']);
        $this->assertSame(3, $flagged[0]['cards_count']);
        $this->assertSame(
            ['6037991111111111', '6037992222222222', '6037993333333333'],
            $flagged[0]['cards'],
        );
        $this->assertSame(4, $service->stats()['total_cards']);
    }

    public function test_a_chat_outside_the_look_back_window_is_not_flagged(): void
    {
        $service = app(CardTrackService::class);

        $this->swipe('553', '6037991111111111', 40);
        $this->swipe('553', '6037992222222222', 45);
        $this->swipe('553', '6037993333333333', 50);

        $this->assertSame([], $service->flaggedChats());
    }

    // -----------------------------------------------------------------
    // The threshold
    // -----------------------------------------------------------------

    public function test_the_threshold_form_persists_and_raises_the_bar(): void
    {
        $service = app(CardTrackService::class);

        foreach (['6037991111111111', '6037992222222222', '6037993333333333'] as $card) {
            $service->record('553', $card);
        }

        $this->postingAs()
            ->post(route('admin.cards.threshold'), ['threshold' => '5'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('panel_settings', ['setting_key' => 'card_multi_threshold', 'setting_value' => '5']);
        $this->assertSame(5, $service->threshold());
        $this->assertSame([], $service->flaggedChats(), 'three cards no longer reach a threshold of five');
    }

    public function test_the_threshold_is_floored_at_two(): void
    {
        $service = app(CardTrackService::class);

        // Validation refuses anything smaller.
        $this->postingAs()
            ->post(route('admin.cards.threshold'), ['threshold' => '1'])
            ->assertSessionHasErrors('threshold');

        $this->assertDatabaseMissing('panel_settings', ['setting_key' => 'card_multi_threshold']);

        // And the stored value can never read below two either.
        PanelSetting::query()->create(['setting_key' => 'card_multi_threshold', 'setting_value' => '0']);
        $this->assertSame(2, $service->threshold());
    }

    public function test_the_page_renders_the_flagged_chat_and_its_card_list(): void
    {
        $service = app(CardTrackService::class);

        $service->record('553', '6037991111111111');
        $service->record('553', '6037992222222222');
        $service->record('553', '6037993333333333');

        $this->postingAs()
            ->get(route('admin.cards.index'))
            ->assertOk()
            ->assertSee('کارت‌های مشکوک')
            ->assertSee('553')
            // The card prints grouped in fours, like the fork's fmtCard().
            ->assertSee('6037 9911 1111 1111', false)
            ->assertSee('آستانهٔ هشدار');
    }

    public function test_an_editor_can_read_but_not_write_the_threshold(): void
    {
        $this->postingAs(AdminRole::Editor)
            ->get(route('admin.cards.index'))
            ->assertOk();

        $this->postingAs(AdminRole::Editor)
            ->post(route('admin.cards.threshold'), ['threshold' => '7'])
            ->assertForbidden();

        $this->assertDatabaseMissing('panel_settings', ['setting_key' => 'card_multi_threshold']);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.cards.index'))->assertRedirect(route('admin.login'));
    }
}
