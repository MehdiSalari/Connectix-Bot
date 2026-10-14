<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FlashSale;
use App\Services\Shop\FlashSaleBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * حراج زمان‌دار — bot-side flash-sale service.
 *
 * Covers: banner text with original + sale + remaining time, activeForPlan
 * returning the live sale or null, and notifyAdmins broadcasting to the
 * configured admin ids.
 */
class FlashSaleBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.admin_ids' => ['100', '200'],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);
    }

    private function makeSale(string $planId, string $type, int $value, string $startsAt, string $endsAt): FlashSale
    {
        return FlashSale::query()->create([
            'plan_id' => $planId,
            'plan_title' => 'Test Plan',
            'discount_type' => $type,
            'discount_value' => $value,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => true,
        ]);
    }

    public function test_banner_text_shows_original_sale_and_remaining_time(): void
    {
        $this->makeSale('plan-x', 'percent', 20, '2020-01-01 00:00:00', '2100-01-01 00:00:00');

        $text = FlashSaleBotService::bannerText('plan-x', 100000);

        $this->assertStringContainsString('حراج ویژه', $text);
        $this->assertStringContainsString('100,000', $text);
        $this->assertStringContainsString('80,000', $text);
        $this->assertStringContainsString('زمان باقی‌مانده', $text);
    }

    public function test_banner_text_is_empty_when_no_sale_is_live(): void
    {
        $this->makeSale('plan-x', 'percent', 20, '2030-01-01 00:00:00', '2100-01-01 00:00:00');

        $this->assertSame('', FlashSaleBotService::bannerText('plan-x', 100000));
    }

    public function test_active_for_plan_returns_the_live_sale_or_null(): void
    {
        $this->makeSale('plan-x', 'percent', 20, '2020-01-01 00:00:00', '2100-01-01 00:00:00');

        $active = FlashSaleBotService::activeForPlan('plan-x');
        $this->assertIsArray($active);
        $this->assertSame('plan-x', $active['plan_id']);
        $this->assertTrue(FlashSaleBotService::isOnSale('plan-x'));
        $this->assertNull(FlashSaleBotService::activeForPlan('plan-y'));
        $this->assertFalse(FlashSaleBotService::isOnSale('plan-y'));
    }

    public function test_notify_admins_broadcasts_to_every_configured_admin_id(): void
    {
        $result = FlashSaleBotService::notifyAdmins('chat-1', 'plan-x', '🔥 حراج ویژه');

        $this->assertSame(2, $result['sent']);
        $this->assertSame(0, $result['failed']);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        // recorded() yields [request, response] pairs, not bare requests.
        $targets = collect($requests)->map(fn (array $pair) => (string) $pair[0]->url())->all();
        $this->assertContains('https://api.telegram.org/bottest-token/sendMessage', $targets);
    }

    public function test_notify_admins_handles_failure_without_throwing(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'blocked'], 403),
        ]);

        $result = FlashSaleBotService::notifyAdmins('chat-1', 'plan-x', '🔥 حراج ویژه');

        $this->assertSame(0, $result['sent']);
        $this->assertSame(2, $result['failed']);
    }

    public function test_notify_admins_with_no_admin_ids_is_a_noop(): void
    {
        config(['connectix_bot.admin_ids' => []]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $result = FlashSaleBotService::notifyAdmins('chat-1', 'plan-x', '🔥 حراج ویژه');

        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('no admin ids configured', $result['note']);
    }
}
