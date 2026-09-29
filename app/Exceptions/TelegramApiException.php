<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Raised when the Telegram Bot API returns a non-ok envelope or the transport
 * fails. Carries the API `description` so callers can log it without leaking
 * the bot token.
 */
class TelegramApiException extends Exception
{
    public function __construct(
        string $message,
        private readonly ?string $description = null,
        private readonly ?int $errorCode = null,
        private readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(string $method, array $payload): self
    {
        $description = is_string($payload['description'] ?? null)
            ? $payload['description']
            : 'Unknown Telegram API error';

        return new self(
            sprintf('Telegram API call [%s] failed: %s', $method, $description),
            $description,
            isset($payload['error_code']) ? (int) $payload['error_code'] : null,
            isset($payload['parameters']['retry_after']) ? (int) $payload['parameters']['retry_after'] : null,
        );
    }

    public static function transport(string $method, string $reason): self
    {
        return new self(
            sprintf('Telegram API call [%s] failed to reach the server: %s', $method, $reason),
            $reason,
        );
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function errorCode(): ?int
    {
        return $this->errorCode;
    }

    /**
     * The `parameters.retry_after` of a flood-control (429) answer, in seconds.
     *
     * Telegram answers an over-rate send with a non-ok envelope plus the number
     * of seconds the caller must wait, so a fan-out can pause instead of losing
     * the recipient.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
