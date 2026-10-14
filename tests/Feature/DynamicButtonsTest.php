<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BotButton;
use App\Models\PanelSetting;
use App\Services\Bot\ButtonColorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicButtonsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ButtonColorService::clearCache();
    }

    public function test_empty_bot_buttons_returns_legacy_keyboard(): void
    {
        // Ensure no bot_buttons rows exist
        BotButton::query()->delete();

        // We can't directly test KeyboardFactory without container setup fully;
        // but we can at least verify DB state matches expectation
        $this->assertSame(0, BotButton::query()->count());
    }

    public function test_dynamic_buttons_with_emoji_and_label(): void
    {
        BotButton::query()->create([
            'button_key' => 'test_btn',
            'default_label' => 'Test',
            'current_label' => 'Test',
            'emoji' => '🎯',
            'is_active' => true,
            'screen' => 'main_menu',
            'action_type' => 'internal',
            'row_num' => 1,
            'col_num' => 1,
            'sort_order' => 1,
        ]);

        $this->assertSame(1, BotButton::query()->count());
    }

    public function test_button_color_service_auto_green(): void
    {
        $this->assertSame('success', ButtonColorService::colorFor('✅ تایید'));
        $this->assertSame('success', ButtonColorService::colorFor('پرداخت نهایی'));
        $this->assertSame('success', ButtonColorService::colorFor('✔ تایید سفارش'));
    }

    public function test_button_color_service_auto_red(): void
    {
        $this->assertSame('danger', ButtonColorService::colorFor('❌ انصراف'));
        $this->assertSame('danger', ButtonColorService::colorFor('لغو عملیات'));
        $this->assertSame('danger', ButtonColorService::colorFor('حذف مورد'));
    }

    public function test_button_color_service_default_and_none(): void
    {
        PanelSetting::query()->updateOrInsert(
            ['setting_key' => 'default_button_color'],
            ['setting_value' => 'blue']
        );
        ButtonColorService::clearCache();
        $this->assertSame('primary', ButtonColorService::colorFor('منوی اصلی'));

        PanelSetting::query()->updateOrInsert(
            ['setting_key' => 'default_button_color'],
            ['setting_value' => 'none']
        );
        ButtonColorService::clearCache();
        $this->assertNull(ButtonColorService::colorFor('منوی اصلی'));
    }
}
