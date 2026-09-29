<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ConnectixApiException;
use App\Services\Connectix\ConnectixService;
use App\Services\Coupon\CouponService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The coupon validation ladder and the discount arithmetic ported from
 * `checkCoupon()`, the `discount` branch of bot.php and `discount()`.
 */
class CouponServiceTest extends TestCase
{
    private CouponService $coupons;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.connectix.base_url' => 'https://api.connectix.test',
            'connectix_bot.connectix.token' => 'panel-token',
        ]);

        $this->coupons = new CouponService(new ConnectixService);
    }

    /**
     * @param  array<int, array<string, mixed>>  $coupons
     */
    private function fakeCoupons(array $coupons): void
    {
        Http::fake([
            'api.connectix.test/v1/seller/seller-plans/coupons' => Http::response(['coupons' => $coupons], 200),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function coupon(array $overrides = []): array
    {
        return array_merge([
            'coupon_code' => 'SAVE10',
            'is_active' => true,
            'is_applied_to_all_plans' => true,
            'per_cent' => 10,
        ], $overrides);
    }

    public function test_it_finds_a_coupon_by_code(): void
    {
        $this->fakeCoupons([$this->coupon(), $this->coupon(['coupon_code' => 'OTHER'])]);

        $found = $this->coupons->findByCode('SAVE10');

        $this->assertNotNull($found);
        $this->assertSame('SAVE10', $found['coupon_code']);
    }

    public function test_an_unknown_code_is_reported_as_invalid(): void
    {
        $this->fakeCoupons([$this->coupon()]);

        $this->assertSame(CouponService::ERROR_INVALID, $this->coupons->rejectionReason('NOPE'));
        $this->assertNull($this->coupons->findByCode('NOPE'));
    }

    public function test_a_blank_code_never_reaches_the_panel(): void
    {
        Http::fake([
            'api.connectix.test/*' => Http::response(['coupons' => []], 200),
        ]);

        $this->assertSame(CouponService::ERROR_INVALID, $this->coupons->rejectionReason('   '));

        Http::assertNothingSent();
    }

    public function test_an_inactive_coupon_is_rejected(): void
    {
        $this->fakeCoupons([$this->coupon(['is_active' => false])]);

        $this->assertSame(CouponService::ERROR_INACTIVE, $this->coupons->rejectionReason('SAVE10'));
    }

    public function test_the_panel_string_true_is_treated_as_active(): void
    {
        // Legacy compared with `== true`, so "1" from the panel meant active.
        $this->fakeCoupons([$this->coupon(['is_active' => '1'])]);

        $this->assertNull($this->coupons->rejectionReason('SAVE10'));
    }

    public function test_a_coupon_outside_its_window_is_rejected(): void
    {
        $this->fakeCoupons([$this->coupon([
            'start_date_text' => '2999-01-01T00:00:00.000000Z',
        ])]);

        $this->assertSame(CouponService::ERROR_EXPIRED, $this->coupons->rejectionReason('SAVE10'));
    }

    public function test_an_expired_coupon_is_rejected(): void
    {
        $this->fakeCoupons([$this->coupon([
            'end_date_text' => '2000-01-01T00:00:00.000000Z',
        ])]);

        $this->assertSame(CouponService::ERROR_EXPIRED, $this->coupons->rejectionReason('SAVE10'));
    }

    public function test_a_coupon_inside_its_window_is_accepted(): void
    {
        $this->fakeCoupons([$this->coupon([
            'start_date_text' => '2000-01-01T00:00:00.000000Z',
            'end_date_text' => '2999-01-01T00:00:00.000000Z',
        ])]);

        $this->assertNull($this->coupons->rejectionReason('SAVE10'));
    }

    public function test_a_plan_restricted_coupon_is_refused_for_another_plan(): void
    {
        $this->fakeCoupons([$this->coupon([
            'is_applied_to_all_plans' => false,
            'plans_ids' => [7, 9],
        ])]);

        $this->assertSame(CouponService::ERROR_PLAN_MISMATCH, $this->coupons->rejectionReason('SAVE10', 8));
        $this->assertNull($this->coupons->rejectionReason('SAVE10', 9));
    }

    public function test_an_all_plans_coupon_ignores_the_plan_id(): void
    {
        $this->fakeCoupons([$this->coupon(['plans_ids' => []])]);

        $this->assertNull($this->coupons->rejectionReason('SAVE10', 123));
    }

    public function test_a_percentage_discount_reduces_the_price(): void
    {
        $this->fakeCoupons([$this->coupon(['per_cent' => 10])]);

        $result = $this->coupons->apply($this->coupon(['per_cent' => 10]), 100000);

        $this->assertTrue($result->isUsable());
        $this->assertSame(100000, $result->originalPrice);
        $this->assertSame(10000, $result->discountAmount);
        $this->assertSame(90000, $result->finalPrice);
    }

    public function test_a_percentage_discount_returns_an_int_not_a_float(): void
    {
        // 100000 * 7 / 100 is 7000.000000000004 in floating point.
        $result = $this->coupons->apply($this->coupon(['per_cent' => 7]), 100000);

        $this->assertSame(7000, $result->discountAmount);
        $this->assertSame(93000, $result->finalPrice);
    }

    public function test_a_flat_discount_is_applied_when_there_is_no_percentage(): void
    {
        $coupon = $this->coupon(['per_cent' => 0, 'amount' => 25000]);

        $result = $this->coupons->apply($coupon, 100000);

        $this->assertSame(25000, $result->discountAmount);
        $this->assertSame(75000, $result->finalPrice);
    }

    public function test_a_flat_discount_never_produces_a_negative_price(): void
    {
        $result = $this->coupons->apply($this->coupon(['per_cent' => 0, 'amount' => 500000]), 100000);

        $this->assertSame(100000, $result->discountAmount);
        $this->assertSame(0, $result->finalPrice);
    }

    public function test_an_oversized_percentage_is_clamped_at_zero(): void
    {
        // Legacy only clamped the flat amount branch, so a coupon above 100%
        // produced a negative price.
        $result = $this->coupons->apply($this->coupon(['per_cent' => 150]), 100000);

        $this->assertSame(0, $result->finalPrice);
    }

    public function test_a_coupon_without_a_discount_value_is_reported_as_unusable(): void
    {
        $result = $this->coupons->apply($this->coupon(['per_cent' => 0, 'amount' => 0]), 100000);

        $this->assertFalse($result->isUsable());
        $this->assertSame(100000, $result->finalPrice);
        $this->assertSame(0, $result->discountAmount);
    }

    public function test_a_formatted_price_loses_its_separators(): void
    {
        $result = $this->coupons->apply($this->coupon(['per_cent' => 10]), '120,000');

        $this->assertSame(120000, $result->originalPrice);
        $this->assertSame(108000, $result->finalPrice);
    }

    public function test_a_zero_string_discount_is_treated_as_absent(): void
    {
        // Legacy's `!empty()` guard rejects the string "0" as a discount.
        $result = $this->coupons->apply($this->coupon(['per_cent' => '0', 'amount' => '0']), 100000);

        $this->assertFalse($result->isUsable());
    }

    public function test_the_formatted_amount_matches_the_legacy_message(): void
    {
        $result = $this->coupons->apply($this->coupon(['per_cent' => 10]), 1000000);

        $this->assertSame('100,000', $result->formattedAmount());
    }

    public function test_a_panel_outage_does_not_claim_the_code_is_wrong(): void
    {
        Http::fake([
            'api.connectix.test/*' => Http::response(['message' => 'server error'], 500),
        ]);

        // Deliberately not ERROR_INVALID: the panel being down says nothing
        // about whether the user's code exists.
        $this->assertSame(CouponService::ERROR_UNAVAILABLE, $this->coupons->rejectionReason('SAVE10'));
    }

    public function test_find_by_code_still_surfaces_a_panel_failure(): void
    {
        Http::fake([
            'api.connectix.test/*' => Http::response(['message' => 'server error'], 500),
        ]);

        // The low level lookup stays honest; only the flow level method
        // degrades, so a caller that wants to retry can see the failure.
        $this->expectException(ConnectixApiException::class);

        $this->coupons->findByCode('SAVE10');
    }
}
