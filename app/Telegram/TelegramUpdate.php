<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Support\Arr;

/**
 * Typed read model over a Telegram Update object.
 *
 * Legacy read the raw superglobal array in bot.php and threaded the values
 * through global constants (UID, CBID, CBMID). This object carries the same
 * information explicitly so no global state is needed.
 */
final readonly class TelegramUpdate
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    public function updateId(): ?int
    {
        $id = $this->raw['update_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function message(): ?array
    {
        $message = $this->raw['message'] ?? null;

        return is_array($message) ? $message : null;
    }

    public function callbackQuery(): ?array
    {
        $query = $this->raw['callback_query'] ?? null;

        return is_array($query) ? $query : null;
    }

    /**
     * Chat id of whichever part of the update carries it.
     *
     * Legacy: `$uid = $chat_id ?? $callback_chat_id`.
     */
    public function chatId(): ?string
    {
        $chatId = $this->message()['chat']['id']
            ?? $this->callbackQuery()['message']['chat']['id']
            ?? null;

        return $chatId === null ? null : (string) $chatId;
    }

    /**
     * Telegram username without the leading `@`, as stored on `users`.
     */
    public function username(): ?string
    {
        $username = $this->message()['chat']['username']
            ?? $this->callbackQuery()['message']['chat']['username']
            ?? null;

        return $username === null ? null : (string) $username;
    }

    public function firstName(): ?string
    {
        $name = $this->message()['chat']['first_name']
            ?? $this->callbackQuery()['message']['chat']['first_name']
            ?? null;

        return $name === null ? null : (string) $name;
    }

    public function text(): ?string
    {
        $text = $this->message()['text'] ?? null;

        return $text === null ? null : (string) $text;
    }

    public function caption(): ?string
    {
        $caption = $this->message()['caption'] ?? null;

        return $caption === null ? null : (string) $caption;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function photo(): array
    {
        $photo = $this->message()['photo'] ?? [];

        return is_array($photo) ? array_values($photo) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function document(): ?array
    {
        $document = $this->message()['document'] ?? null;

        return is_array($document) ? $document : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function contact(): ?array
    {
        $contact = $this->message()['contact'] ?? null;

        return is_array($contact) ? $contact : null;
    }

    public function callbackData(): ?string
    {
        $data = $this->callbackQuery()['data'] ?? null;

        return $data === null ? null : (string) $data;
    }

    public function callbackId(): ?string
    {
        $id = $this->callbackQuery()['id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    /**
     * Message id the callback query is attached to.
     */
    public function callbackMessageId(): ?int
    {
        $id = $this->callbackQuery()['message']['message_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function callbackMessageText(): ?string
    {
        $text = $this->callbackQuery()['message']['text'] ?? null;

        return $text === null ? null : (string) $text;
    }

    /**
     * The Telegram user id behind the update. Used for the admin check, which
     * legacy performed against the chat id.
     */
    public function fromUserId(): ?string
    {
        $id = $this->raw['callback_query']['from']['id']
            ?? $this->message()['from']['id']
            ?? null;

        return $id === null ? null : (string) $id;
    }

    /**
     * Whether the update carries a callback query.
     */
    public function isCallbackQuery(): bool
    {
        return $this->callbackQuery() !== null;
    }

    /**
     * Largest available photo size, which is the one legacy submits as the
     * payment receipt.
     *
     * @return array<string, mixed>|null
     */
    public function largestPhoto(): ?array
    {
        $photo = $this->photo();

        if ($photo === []) {
            return null;
        }

        return Arr::last($photo);
    }
}
