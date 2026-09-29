<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Telegram\TelegramGateway;
use Mockery;
use Tests\TestCase;

/**
 * The webhook contract: authenticate, acknowledge, never leak failures.
 */
class TelegramWebhookTest extends TestCase
{
    private const SECRET = 'a-very-long-random-secret';

    /**
     * A minimal but realistic /start update.
     *
     * @return array<string, mixed>
     */
    private function startUpdate(): array
    {
        return [
            'update_id' => 1000,
            'message' => [
                'message_id' => 7,
                'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Ali'],
                'chat' => ['id' => 555, 'first_name' => 'Ali', 'username' => 'ali'],
                'date' => 1700000000,
                'text' => '/start',
            ],
        ];
    }

    public function test_it_rejects_a_request_without_the_secret_header(): void
    {
        config(['connectix_bot.telegram.webhook_secret' => self::SECRET]);

        $this->postJson('/telegram/webhook', $this->startUpdate())
            ->assertForbidden();
    }

    public function test_it_rejects_a_request_with_a_wrong_secret(): void
    {
        config(['connectix_bot.telegram.webhook_secret' => self::SECRET]);

        $this->postJson('/telegram/webhook', $this->startUpdate(), [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
        ])->assertForbidden();
    }

    public function test_it_rejects_a_secret_that_is_a_prefix_of_the_real_one(): void
    {
        config(['connectix_bot.telegram.webhook_secret' => self::SECRET]);

        $this->postJson('/telegram/webhook', $this->startUpdate(), [
            'X-Telegram-Bot-Api-Secret-Token' => substr(self::SECRET, 0, 10),
        ])->assertForbidden();
    }

    public function test_it_accepts_a_request_carrying_the_configured_secret(): void
    {
        config(['connectix_bot.telegram.webhook_secret' => self::SECRET]);

        $this->swap(TelegramGateway::class, Mockery::mock(TelegramGateway::class));

        $this->postJson('/telegram/webhook', $this->startUpdate(), [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ])->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_it_acknowledges_an_empty_payload_without_touching_the_gateway(): void
    {
        config(['connectix_bot.telegram.webhook_secret' => self::SECRET]);

        $gateway = Mockery::mock(TelegramGateway::class);
        $gateway->shouldNotReceive('handle');
        $this->swap(TelegramGateway::class, $gateway);

        $this->postJson('/telegram/webhook', [], [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ])->assertOk()->assertExactJson(['ok' => true]);
    }

    /**
     * Telegram retries with backoff when it does not get a 2xx. Acknowledging
     * a failed update is what stops a bad payload from becoming a retry storm.
     */
    public function test_it_acknowledges_even_when_handling_throws(): void
    {
        config(['connectix_bot.telegram.webhook_secret' => self::SECRET]);

        $gateway = Mockery::mock(TelegramGateway::class);
        $gateway->shouldReceive('handle')->andThrow(new \RuntimeException('boom'));
        $this->swap(TelegramGateway::class, $gateway);

        $this->postJson('/telegram/webhook', $this->startUpdate(), [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ])->assertOk()->assertExactJson(['ok' => true]);
    }

    /**
     * An empty configured secret is a misconfiguration, not a free pass:
     * failing closed keeps anyone from posting updates while the webhook is
     * in an unverified state. A fresh local install gets a random secret
     * written at install time, so this branch only appears on broken setups.
     */
    public function test_it_refuses_requests_when_the_secret_is_blank(): void
    {
        config(['connectix_bot.telegram.webhook_secret' => '']);

        $gateway = Mockery::mock(TelegramGateway::class);
        $gateway->shouldNotReceive('handle');
        $this->swap(TelegramGateway::class, $gateway);

        $this->postJson('/telegram/webhook', $this->startUpdate())->assertForbidden();
    }

    public function test_the_csrf_token_is_not_required_for_the_webhook(): void
    {
        config(['connectix_bot.telegram.webhook_secret' => self::SECRET]);

        $this->swap(TelegramGateway::class, Mockery::mock(TelegramGateway::class));

        // No session, no CSRF token: this mirrors a real Telegram delivery.
        $this->post('/telegram/webhook', $this->startUpdate(), [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ])->assertOk();
    }
}
