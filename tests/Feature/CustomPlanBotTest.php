<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomPlanSetting;
use App\Services\Shop\CustomPlanBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomPlanBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.base_url' => 'https://api.connectix.vip',
            'connectix_bot.admin_ids' => ['123'],
        ]);
    }

    private function createCustomPlanSettings(array $overrides = []): void
    {
        if (! Schema::hasTable('custom_plan_settings')) {
            \Schema::create('custom_plan_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->boolean('is_active')->default(true);
                $table->integer('price_per_gb')->default(5000);
                $table->integer('price_per_day')->default(1000);
                $table->decimal('min_gb', 8, 2)->default(1);
                $table->decimal('max_gb', 8, 2)->default(1000);
                $table->integer('min_days')->default(1);
                $table->integer('max_days')->default(365);
                $table->integer('default_devices')->default(1);
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (Schema::hasTable('payments')) {
            if (! Schema::hasColumn('payments', 'custom_gb')) {
                \Schema::table('payments', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->decimal('custom_gb', 8, 2)->nullable();
                });
            }
            if (! Schema::hasColumn('payments', 'custom_days')) {
                \Schema::table('payments', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->integer('custom_days')->nullable();
                });
            }
        }

        CustomPlanSetting::query()->updateOrCreate(
            ['id' => 1],
            array_merge([
                'is_active' => true,
                'price_per_gb' => 5000,
                'price_per_day' => 1000,
                'min_gb' => 1,
                'max_gb' => 1000,
                'min_days' => 1,
                'max_days' => 365,
                'default_devices' => 1,
            ], $overrides)
        );
    }

    public function test_pricefor_math(): void
    {
        $this->createCustomPlanSettings([
            'price_per_gb' => 5000,
            'price_per_day' => 1000,
            'min_gb' => 1,
            'max_gb' => 1000,
            'min_days' => 1,
            'max_days' => 365,
        ]);

        $result = CustomPlanBotService::priceFor(10, 30);
        $this->assertTrue($result['ok']);
        $this->assertSame(10 * 5000 + 30 * 1000, $result['total']);
    }

    public function test_validation_rejects_gb_below_min_gb_with_fork_error(): void
    {
        $this->createCustomPlanSettings([
            'min_gb' => 5,
            'max_gb' => 100,
            'min_days' => 1,
            'max_days' => 365,
        ]);

        $result = CustomPlanBotService::priceFor(1, 30);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('حداقل حجم', $result['error']);
    }

    public function test_validation_rejects_gb_above_max_gb(): void
    {
        $this->createCustomPlanSettings([
            'min_gb' => 1,
            'max_gb' => 10,
            'min_days' => 1,
            'max_days' => 365,
        ]);

        $result = CustomPlanBotService::priceFor(20, 30);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('حداکثر حجم', $result['error']);
    }

    public function test_validation_rejects_days_above_max_days(): void
    {
        $this->createCustomPlanSettings([
            'min_gb' => 1,
            'max_gb' => 100,
            'min_days' => 1,
            'max_days' => 30,
        ]);

        $result = CustomPlanBotService::priceFor(10, 60);
        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }

    public function test_settings_disabled_returns_fork_refusal(): void
    {
        $this->createCustomPlanSettings([
            'is_active' => false,
        ]);

        $result = CustomPlanBotService::priceFor(10, 30);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('غیرفعال', $result['error']);
    }

    public function test_createorder_persists_state_with_gb_days_total(): void
    {
        $this->createCustomPlanSettings();
        $this->makeUser('1001', 'Test');
        $user = \App\Models\User::where('chat_id', '1001')->first();
        $state = app(\App\Services\User\UserStateService::class);
        $state->set($user, [
            'gb' => 10,
            'days' => 30,
        ]);
        $handler = app(\App\Telegram\Handlers\CustomPlanHandler::class);
        $update = \App\Telegram\TelegramUpdate::fromArray([
            'callback_query' => [
                'id' => 'cb1',
                'data' => 'customplan_buy:1',
                'message' => [
                    'chat' => ['id' => 1001],
                    'message_id' => 123,
                    'text' => 'test',
                ],
                'from' => ['id' => 1001],
            ],
        ]);
        $handler->handle($update, $user);
        $user->refresh();
        $st = $state->get($user);
        $this->assertNotNull($st);
        $this->assertSame(10, $st['gb']);
        $this->assertSame(30, $st['days']);
        $this->assertArrayHasKey('total', $st);
    }

    public function test_confirm_creates_payments_row_with_method_custom_and_is_paid_null(): void
    {
        $this->createCustomPlanSettings();
        $this->makeUser('1002', 'Test2');
        $user = \App\Models\User::where('chat_id', '1002')->first();
        $state = app(\App\Services\User\UserStateService::class);
        $state->set($user, [
            'action' => 'customplan_pending',
            'chat_id' => '1002',
            'gb' => 10,
            'days' => 30,
            'total' => 80000,
            'plan_id' => '1',
        ]);
        $handler = app(\App\Telegram\Handlers\CustomPlanHandler::class);
        $update = \App\Telegram\TelegramUpdate::fromArray([
            'callback_query' => [
                'id' => 'cb2',
                'data' => 'customplan_confirm:1',
                'message' => [
                    'chat' => ['id' => 1002],
                    'message_id' => 124,
                    'text' => 'test',
                ],
                'from' => ['id' => 1002],
            ],
        ]);
        $handler->handle($update, $user);
        $paymentRow = DB::table('payments')->where('chat_id', '1002')->first();
        $this->assertNotNull($paymentRow);
        $this->assertSame('custom', $paymentRow->method);
        $this->assertNull($paymentRow->is_paid);
    }

    public function test_cancel_clears_state(): void
    {
        $this->createCustomPlanSettings();
        $this->makeUser('1003', 'Test3');
        $user = \App\Models\User::where('chat_id', '1003')->first();
        $state = app(\App\Services\User\UserStateService::class);
        $state->set($user, [
            'action' => 'customplan_pending',
            'gb' => 5,
            'days' => 10,
        ]);
        $handler = app(\App\Telegram\Handlers\CustomPlanHandler::class);
        $update = \App\Telegram\TelegramUpdate::fromArray([
            'callback_query' => [
                'id' => 'cb3',
                'data' => 'customplan_cancel',
                'message' => [
                    'chat' => ['id' => 1003],
                    'message_id' => 125,
                    'text' => 'test',
                ],
                'from' => ['id' => 1003],
            ],
        ]);
        $handler->handle($update, $user);
        $user->refresh();
        $st = $state->get($user);
        $this->assertNull($st);
    }

    private function makeUser(string $chatId, ?string $name = 'Test'): \App\Models\User
    {
        return \App\Models\User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => 'tg_' . $chatId,
            'name' => $name,
            'created_at' => now(),
        ]);
    }
}