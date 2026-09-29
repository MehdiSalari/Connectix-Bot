<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Connectix\ConnectixService;
use App\Services\Plan\PlanService;
use Tests\TestCase;

/**
 * The plan title grammar is the single most regression prone port: the panel
 * encodes devices, traffic, period and features into one string that the bot
 * has to turn back into a label. These cases are taken from the shapes the
 * seller actually produces.
 *
 * @covers \App\Services\Plan\PlanService
 */
class PlanTitleParserTest extends TestCase
{
    private PlanService $plans;

    protected function setUp(): void
    {
        parent::setUp();

        // The parser never calls the panel, so a bare client is enough.
        $this->plans = new PlanService(new ConnectixService());
    }

    public function test_it_parses_a_plain_monthly_plan(): void
    {
        $parsed = $this->plans->parsePlanTitle('(1x) 30M');

        $this->assertSame(1, $parsed['devices']);
        $this->assertFalse($parsed['is_free']);
        $this->assertNull($parsed['traffic_gb']);
        $this->assertSame('30 ماه', $parsed['period_text']);
        $this->assertSame(900, $parsed['period_days']);
        $this->assertSame([], $parsed['extras']);
    }

    public function test_it_parses_devices_and_traffic(): void
    {
        $parsed = $this->plans->parsePlanTitle('(2x) 50GB-3M');

        $this->assertSame(2, $parsed['devices']);
        $this->assertSame(50.0, $parsed['traffic_gb']);
        $this->assertSame('3 ماه', $parsed['period_text']);
    }

    public function test_it_parses_an_unlimited_plan(): void
    {
        $parsed = $this->plans->parsePlanTitle('(1x) Unlimited-1W');

        $this->assertTrue($parsed['is_unlimited']);
        $this->assertSame('∞', $parsed['traffic_gb']);
        $this->assertSame('1 هفته', $parsed['period_text']);
        $this->assertSame(7, $parsed['period_days']);
    }

    public function test_it_recognises_a_free_trial(): void
    {
        $parsed = $this->plans->parsePlanTitle('(1x)Free-1W');

        $this->assertTrue($parsed['is_free']);
        $this->assertSame('تست رایگان • 1 هفته', $parsed['text']);
        $this->assertSame('1 هفته', $parsed['period_text']);
    }

    public function test_it_extracts_the_gift_days(): void
    {
        $parsed = $this->plans->parsePlanTitle('(1x) 30M + 3D');

        $this->assertSame(3, $parsed['gift_days']);
        $this->assertSame('30 ماه', $parsed['period_text']);
        $this->assertContains('+3 روز هدیه', $parsed['extras']);
    }

    public function test_it_extracts_the_sublink_flag(): void
    {
        $parsed = $this->plans->parsePlanTitle('(1x) 30GB-1M + 7D Sublink');

        $this->assertTrue($parsed['has_sublink']);
        $this->assertContains('ساب‌لینک', $parsed['extras']);
        $this->assertContains('+7 روز هدیه', $parsed['extras']);
    }

    /**
     * "BCSublink" contains "Sublink", so the longer feature must win or every
     * business-class plan would be advertised as a plain sublink.
     */
    public function test_business_class_sublink_wins_over_sublink(): void
    {
        $parsed = $this->plans->parsePlanTitle('(2x) 100GB-1M + 3D BCSublink');

        $this->assertContains('بیزینس ساب‌لینک', $parsed['extras']);
        $this->assertNotContains('ساب‌لینک', $parsed['extras']);
        $this->assertTrue($parsed['has_sublink']);
    }

    public function test_an_unknown_title_degrades_instead_of_throwing(): void
    {
        $parsed = $this->plans->parsePlanTitle('something else entirely');

        $this->assertSame('پلن نامشخص', $parsed['text']);
        $this->assertFalse($parsed['is_free']);
        $this->assertNull($parsed['traffic_gb']);
    }

    public function test_period_units_convert_to_days(): void
    {
        $this->assertSame(5, $this->plans->approximateDays(5, 'D'));
        $this->assertSame(14, $this->plans->approximateDays(2, 'W'));
        $this->assertSame(60, $this->plans->approximateDays(2, 'M'));
        $this->assertSame(365, $this->plans->approximateDays(1, 'Y'));
    }

    public function test_group_labels_match_the_legacy_map(): void
    {
        $this->assertSame('ویژه', $this->plans->parseType('default'));
        $this->assertSame('ساب‌لینک', $this->plans->parseType('Sublink'));
        $this->assertSame('بیزینس ساب‌لینک', $this->plans->parseType('BCSublink'));
        $this->assertSame('📱 | ویژه', $this->plans->parseTypeWithEmoji('default'));
    }

    /**
     * The panel's English translation is authoritative; the title is only
     * sniffed when it is missing.
     */
    public function test_group_name_prefers_the_panel_translation(): void
    {
        $this->assertSame('Economic', $this->plans->groupNameFor([
            'group_name_translations' => ['en' => 'Economic'],
            'group_name' => 'اقتصادی',
        ]));

        $this->assertSame('BCSublink', $this->plans->groupNameFor(['title' => '(1x) 30GB-1M BCSublink']));
        $this->assertSame('default', $this->plans->groupNameFor(['title' => '(1x) 30M']));
    }

    public function test_a_plan_must_be_flagged_premium_and_in_group(): void
    {
        $premium = ['is_displayed_in_robot' => true, 'type' => 'Premium', 'title' => '(1x) 30M'];

        $this->assertTrue($this->plans->planMatchesGroup($premium, 'default'));
        $this->assertFalse($this->plans->planMatchesGroup($premium, 'Sublink'));

        $this->assertFalse($this->plans->planMatchesGroup(
            ['is_displayed_in_robot' => false, 'type' => 'Premium', 'title' => '(1x) 30M'],
            'default'
        ), 'a plan hidden from the robot must never be sold');

        $this->assertFalse($this->plans->planMatchesGroup(
            ['is_displayed_in_robot' => true, 'type' => 'Free', 'title' => '(1x)Free-1W'],
            'default'
        ), 'a free trial is not a premium plan');
    }

    public function test_device_counts_are_deduplicated_and_sorted(): void
    {
        $counts = $this->plans->availableDeviceCounts([
            ['count_of_devices' => 3],
            ['count_of_devices' => 1],
            ['count_of_devices' => 3],
            ['count_of_devices' => 2],
            ['count_of_devices' => 0],
        ]);

        $this->assertSame([1, 2, 3], $counts);
    }
}
