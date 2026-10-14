<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FlashSale;
use App\Services\Shop\FlashSaleAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashSalePurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_flash_sale_price_for(): void
    {
        $now = now('Asia/Tehran');
        // Create active flash sale via model directly for test
        FlashSale::query()->create([
            'plan_id' => '123',
            'plan_title' => 'Test Plan',
            'discount_type' => 'percent',
            'discount_value' => 20,
            'starts_at' => $now->copy()->subHour()->format('Y-m-d H:i:s'),
            'ends_at' => $now->copy()->addHour()->format('Y-m-d H:i:s'),
            'is_active' => true,
        ]);

        [$final, $strike] = FlashSaleAdminService::priceWithSale(100000, [
            'discount_type' => 'percent',
            'discount_value' => 20,
        ]);
        $this->assertSame(80000, $final);
        $this->assertSame(100000, $strike);
    }

    public function test_flash_sale_no_sale_outside_window(): void
    {
        [$final, $strike] = FlashSaleAdminService::priceWithSale(100000, null);
        $this->assertSame(100000, $final);
        $this->assertSame(0, $strike);
    }

    public function test_flash_sale_message_line(): void
    {
        $line = FlashSaleAdminService::remainingText(now('Asia/Tehran')->addHours(2)->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('ساعت', $line);
    }
}
