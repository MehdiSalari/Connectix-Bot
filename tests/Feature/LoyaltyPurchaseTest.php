<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_loyalty_placeholder(): void
    {
        $this->assertTrue(true);
    }
}