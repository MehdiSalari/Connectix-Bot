<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Raised when the Connectix seller API is unreachable or returns an error.
 */
class ConnectixApiException extends Exception
{
    public function __construct(
        string $message,
        private readonly ?string $endpoint = null,
        private readonly ?int $statusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(string $endpoint, int $status, string $body): self
    {
        return new self(
            sprintf('Connectix API [%s] returned HTTP %d: %s', $endpoint, $status, self::summarise($body)),
            $endpoint,
            $status,
        );
    }

    public static function transport(string $endpoint, string $reason): self
    {
        return new self(
            sprintf('Connectix API [%s] could not be reached: %s', $endpoint, $reason),
            $endpoint,
        );
    }

    public function endpoint(): ?string
    {
        return $this->endpoint;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Keep the response body short so an unexpectedly large payload cannot
     * flood the log file.
     */
    private static function summarise(string $body): string
    {
        $body = trim($body);

        return mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'…' : $body;
    }
}
