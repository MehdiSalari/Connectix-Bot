<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PaymentCard;
use App\Services\Resellers\CardTrackBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * کارت‌های مشکوک — bot-side card-track reporter.
 *
 * Covers: suspiciousCards() returning cards with count>threshold within the
 * last 7 days, reportText() producing a Persian list, and notifyAdmins()
 * looping the configured admin ids via TelegramService.
 */
class CardTrackBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.admin_ids' => ['123', '456'],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);
    }

    public function test_suspicious_cards_above_threshold(): void
    {
        // Same card used by 4 different chats within 7 days → suspicious (threshold 3)
        PaymentCard::query()->create(['chat_id' => '1', 'card' => '1111222233334444', 'created_at' => now()->subDays(1)]);
        PaymentCard::query()->create(['chat_id' => '2', 'card' => '1111222233334444', 'created_at' => now()->subDays(2)]);
        PaymentCard::query()->create(['chat_id' => '3', 'card' => '1111222233334444', 'created_at' => now()->subDays(3)]);
        PaymentCard::query()->create(['chat_id' => '4', 'card' => '1111222233334444', 'created_at' => now()->subDays(4)]);

        // Another card used by 2 chats → not suspicious (need > 3)
        PaymentCard::query()->create(['chat_id' => '5', 'card' => '5555666677778888', 'created_at' => now()->subDays(1)]);
        PaymentCard::query()->create(['chat_id' => '6', 'card' => '5555666677778888', 'created_at' => now()->subDays(2)]);

        $cards = CardTrackBotService::suspiciousCards(3);

        $this->assertCount(1, $cards);
        $this->assertSame('1111222233334444', $cards[0]['card']);
        $this->assertSame(4, $cards[0]['users_count']);
    }

    public function test_suspicious_cards_ignores_old_records_outside_lookback(): void
    {
        PaymentCard::query()->create(['chat_id' => '1', 'card' => '1111222233334444', 'created_at' => now()->subDays(10)]);
        PaymentCard::query()->create(['chat_id' => '2', 'card' => '1111222233334444', 'created_at' => now()->subDays(20)]);

        $cards = CardTrackBotService::suspiciousCards(1);

        $this->assertCount(0, $cards);
    }

    public function test_suspicious_cards_is_empty_when_no_shared_cards(): void
    {
        PaymentCard::query()->create(['chat_id' => '1', 'card' => '1111222233334444', 'created_at' => now()->subDays(1)]);

        $cards = CardTrackBotService::suspiciousCards(1);

        $this->assertCount(0, $cards);
    }

    public function test_report_text_produces_persian_list(): void
    {
        $cards = [
            [
                'card'        => '1111222233334444',
                'users_count' => 4,
                'uses'        => 4,
                'chat_ids'    => '1,2,3,4',
                'last_seen'   => now()->format('Y-m-d H:i:s'),
            ],
        ];

        $text = CardTrackBotService::reportText($cards);

        $this->assertStringContainsString('گزارش کارت‌های مشکوک', $text);
        $this->assertStringContainsString('****4444', $text);
        $this->assertStringContainsString('تعداد کاربرهای متفاوت', $text);
        $this->assertStringContainsString('1</code>، <code>2</code>', $text);
    }

    public function test_report_text_handles_empty_case(): void
    {
        $text = CardTrackBotService::reportText([]);

        $this->assertSame('هیچ کارت مشکوکی در ۷ روز گذشته یافت نشد.', $text);
    }

    public function test_notify_admins_loops_configured_admin_ids(): void
    {
        $result = CardTrackBotService::notifyAdmins('test report');

        $this->assertSame(2, $result['sent']);
        $this->assertSame(0, $result['failed']);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
    }
}