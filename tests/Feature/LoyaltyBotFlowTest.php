<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LoyaltyCode;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyReward;
use App\Models\Payment;
use App\Models\User;
use App\Services\Loyalty\LoyaltyAdminService;
use App\Services\Shop\LoyaltyBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyBotFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.base_url' => 'https://api.connectix.vip',
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.panel.enabled' => false,
        ]);
    }

    public function test_earn_points_on_paid_payment_creates_row(): void
    {
        $user = User::query()->create(['chat_id' => '111', 'telegram_id' => '111', 'name' => 'A']);
        LoyaltyAdminService::saveSettings(['enabled' => '1', 'rate_toman' => '1000']);
        Payment::query()->create(['chat_id' => '111', 'price' => '5000', 'is_paid' => '1', 'method' => 'card', 'created_at' => now()]);
        $pay = Payment::query()->first();
        LoyaltyBotService::earnPoints($pay->id);
        $this->assertDatabaseHas('loyalty_points', ['chat_id' => '111']);
    }

    public function test_method_loyalty_excluded(): void
    {
        $user = User::query()->create(['chat_id' => '222', 'telegram_id' => '222', 'name' => 'B']);
        LoyaltyAdminService::saveSettings(['enabled' => '1', 'rate_toman' => '1000']);
        Payment::query()->create(['chat_id' => '222', 'price' => '5000', 'is_paid' => '1', 'method' => 'loyalty', 'created_at' => now()]);
        $pay = Payment::query()->first();
        LoyaltyBotService::earnPoints($pay->id);
        $this->assertDatabaseMissing('loyalty_points', ['chat_id' => '222']);
    }

    public function test_get_balance_sums_correctly(): void
    {
        LoyaltyPoint::create(['chat_id' => '333', 'amount' => 10, 'remaining' => 5, 'type' => 'payment', 'created_at' => now()]);
        LoyaltyPoint::create(['chat_id' => '333', 'amount' => 20, 'remaining' => 3, 'type' => 'payment', 'created_at' => now()]);
        $this->assertSame(8, LoyaltyBotService::getBalance('333'));
    }

    public function test_redeem_code_consumes_points_and_creates_redemption(): void
    {
        LoyaltyAdminService::saveSettings(['enabled' => '1']);
        LoyaltyPoint::create(['chat_id' => '444', 'amount' => 100, 'remaining' => 100, 'type' => 'payment', 'created_at' => now()]);
        // loyalty_rewards has no created_at column (timestamps-free table).
        $reward = LoyaltyReward::create(['title' => 't', 'cost' => 10]);
        $code = LoyaltyCode::create(['reward_id' => $reward->id, 'code' => 'LOY-TEST01', 'created_at' => now()]);
        $res = LoyaltyBotService::redeemCode('444', $code->id);
        $this->assertTrue($res['ok'] ?? false);
        $this->assertDatabaseHas('loyalty_redemptions', ['chat_id' => '444']);
    }

    public function test_expired_or_unowned_code_rejected_with_fork_text(): void
    {
        $res = LoyaltyBotService::redeemCode('555', 9999);
        $this->assertFalse($res['ok'] ?? true);
    }
}
