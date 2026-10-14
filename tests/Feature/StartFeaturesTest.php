<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PanelSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WelcomeCreditLog;
use App\Services\Features\StartEmojiAdminService;
use App\Services\Features\WelcomeGiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StartFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);
    }

    private function makeUser(string $chatId = '555'): User
    {
        $user = User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => 'tg_'.$chatId,
            'name' => 'Test',
        ]);
        Wallet::query()->create([
            'chat_id' => $chatId,
            'balance' => '0',
        ]);

        return $user;
    }

    private function setWelcomeCredit(array $overrides = []): void
    {
        $d = [
            'enabled' => '1',
            'amount' => '5000',
            'starts_at' => '',
            'ends_at' => '',
            'notify' => '1',
            'message' => '🎁 {amount}',
        ];
        foreach ($overrides as $k => $v) {
            $d[$k] = $v;
        }
        PanelSetting::query()->updateOrInsert(['setting_key' => 'welcome_credit_enabled'], ['setting_value' => $d['enabled']]);
        PanelSetting::query()->updateOrInsert(['setting_key' => 'welcome_credit_amount'], ['setting_value' => $d['amount']]);
        PanelSetting::query()->updateOrInsert(['setting_key' => 'welcome_credit_starts_at'], ['setting_value' => $d['starts_at']]);
        PanelSetting::query()->updateOrInsert(['setting_key' => 'welcome_credit_ends_at'], ['setting_value' => $d['ends_at']]);
        PanelSetting::query()->updateOrInsert(['setting_key' => 'welcome_credit_notify'], ['setting_value' => $d['notify']]);
        PanelSetting::query()->updateOrInsert(['setting_key' => 'welcome_credit_message'], ['setting_value' => $d['message']]);
    }

    public function test_start_emoji_render(): void
    {
        PanelSetting::query()->updateOrInsert(['setting_key' => 'start_emoji_enabled'], ['setting_value' => '1']);
        PanelSetting::query()->updateOrInsert(['setting_key' => 'start_emoji_custom_emoji_id'], ['setting_value' => '123']);
        PanelSetting::query()->updateOrInsert(['setting_key' => 'start_emoji_emoji_char'], ['setting_value' => '👋']);
        $rendered = StartEmojiAdminService::render();
        $this->assertStringContainsString('<tg-emoji emoji-id="123">', $rendered);
        $this->assertStringContainsString('👋', $rendered);
    }

    public function test_welcome_credit_granted_once(): void
    {
        $this->makeUser('555');
        $this->setWelcomeCredit();
        $this->assertTrue(WelcomeGiftService::grantIfEligible('555'));
        $wallet = Wallet::query()->where('chat_id', '555')->first();
        $this->assertNotNull($wallet);
        $this->assertSame('5000', $wallet->balance);
        $this->assertTrue(WelcomeCreditLog::query()->where('chat_id', '555')->exists());
        $this->assertDatabaseHas('wallet_transactions', [
            'chat_id' => '555',
            'type' => 'WELCOME_CREDIT',
        ]);
        // Second grant should not happen
        $this->assertFalse(WelcomeGiftService::grantIfEligible('555'));
    }

    public function test_welcome_credit_not_granted_to_buyer(): void
    {
        $this->makeUser('556');
        $this->setWelcomeCredit();
        // Add successful payment
        DB::table('payments')->insert([
            'chat_id' => '556',
            'is_paid' => '1',
            'method' => 'card',
            'created_at' => now(),
        ]);
        $this->assertFalse(WelcomeGiftService::grantIfEligible('556'));
    }

    public function test_welcome_credit_respects_window(): void
    {
        $this->makeUser('557');
        // Future start
        $this->setWelcomeCredit([
            'starts_at' => now('Asia/Tehran')->addHour()->format('Y-m-d H:i'),
        ]);
        $this->assertFalse(WelcomeGiftService::grantIfEligible('557'));
        // Past end
        $this->setWelcomeCredit([
            'starts_at' => '',
            'ends_at' => now('Asia/Tehran')->subHour()->format('Y-m-d H:i'),
        ]);
        $this->assertFalse(WelcomeGiftService::grantIfEligible('557'));
    }
}
