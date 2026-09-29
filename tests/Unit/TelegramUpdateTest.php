<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Telegram\TelegramUpdate;
use PHPUnit\Framework\TestCase;

/**
 * The typed read model must expose exactly what the legacy code read out of the
 * raw superglobal, for both message and callback updates.
 */
class TelegramUpdateTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function messagePayload(): array
    {
        return [
            'update_id' => 10,
            'message' => [
                'message_id' => 4,
                'from' => ['id' => 555],
                'chat' => ['id' => 555, 'username' => 'ali', 'first_name' => 'Ali'],
                'text' => '/start',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackPayload(): array
    {
        return [
            'update_id' => 11,
            'callback_query' => [
                'id' => 'cb-9',
                'from' => ['id' => 777],
                'message' => [
                    'message_id' => 8,
                    'chat' => ['id' => 555, 'username' => 'ali', 'first_name' => 'Ali'],
                    'text' => 'menu',
                ],
                'data' => 'buy_default',
            ],
        ];
    }

    public function test_it_reads_a_message_update(): void
    {
        $update = TelegramUpdate::fromArray($this->messagePayload());

        $this->assertSame(10, $update->updateId());
        $this->assertSame('555', $update->chatId());
        $this->assertSame('ali', $update->username());
        $this->assertSame('Ali', $update->firstName());
        $this->assertSame('/start', $update->text());
        $this->assertSame('555', $update->fromUserId());
        $this->assertFalse($update->isCallbackQuery());
    }

    public function test_it_reads_a_callback_update(): void
    {
        $update = TelegramUpdate::fromArray($this->callbackPayload());

        $this->assertSame('555', $update->chatId());
        $this->assertSame('cb-9', $update->callbackId());
        $this->assertSame('buy_default', $update->callbackData());
        $this->assertSame(8, $update->callbackMessageId());
        $this->assertSame('menu', $update->callbackMessageText());
        $this->assertTrue($update->isCallbackQuery());
    }

    /**
     * The admin check used the raw user id, which for a callback is not the
     * chat id. Both must resolve without falling through to each other.
     */
    public function test_the_sender_id_comes_from_the_tapping_user(): void
    {
        $update = TelegramUpdate::fromArray($this->callbackPayload());

        $this->assertSame('777', $update->fromUserId());
        $this->assertSame('555', $update->chatId());
    }

    public function test_it_picks_the_largest_photo_size(): void
    {
        $update = TelegramUpdate::fromArray([
            'message' => [
                'photo' => [
                    ['file_id' => 'small', 'width' => 90],
                    ['file_id' => 'medium', 'width' => 320],
                    ['file_id' => 'large', 'width' => 800],
                ],
            ],
        ]);

        $this->assertSame('large', $update->largestPhoto()['file_id']);
    }

    public function test_missing_fields_read_as_null(): void
    {
        $update = TelegramUpdate::fromArray(['update_id' => 1]);

        $this->assertNull($update->message());
        $this->assertNull($update->callbackQuery());
        $this->assertNull($update->chatId());
        $this->assertNull($update->text());
        $this->assertNull($update->callbackData());
        $this->assertNull($update->largestPhoto());
        $this->assertSame([], $update->photo());
    }
}
