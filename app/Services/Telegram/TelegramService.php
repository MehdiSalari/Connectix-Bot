<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Exceptions\TelegramApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin, fully typed wrapper around the Telegram Bot API.
 *
 * Replaces the legacy `tg()` helper so no controller or service has to build
 * cURL requests. The bot token lives in configuration and is never logged.
 */
class TelegramService
{
    private const BASE_URL = 'https://api.telegram.org';

    /** Connection timeout in seconds, matching the legacy CURLOPT_CONNECTTIMEOUT. */
    private const CONNECT_TIMEOUT = 10;

    /** Total request timeout, matching the legacy CURLOPT_TIMEOUT. */
    private const TIMEOUT = 120;

    /**
     * Call an arbitrary Bot API method.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> The decoded `result` payload plus envelope keys.
     *
     * @throws TelegramApiException
     */
    public function call(string $method, array $params = []): array
    {
        $payload = $this->request($method, $params);

        return $payload;
    }

    /**
     * Call a method and return only the `result` node.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     *
     * @throws TelegramApiException
     */
    public function result(string $method, array $params = []): ?array
    {
        $payload = $this->call($method, $params);

        $result = $payload['result'] ?? null;

        return is_array($result) ? $result : null;
    }

    // ---------------------------------------------------------------------
    // Messaging
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws TelegramApiException
     */
    public function sendMessage(int|string $chatId, string $text, array $params = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $params));
    }

    /**
     * Edit the text of an existing message.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws TelegramApiException
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $params = []): array
    {
        return $this->call('editMessageText', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ], $params));
    }

    /**
     * Edit the caption of a message that carries media.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws TelegramApiException
     */
    public function editMessageCaption(int|string $chatId, int $messageId, string $caption, array $params = []): array
    {
        return $this->call('editMessageCaption', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
        ], $params));
    }

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws TelegramApiException
     */
    public function deleteMessage(int|string $chatId, int $messageId): array
    {
        return $this->call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * Acknowledge a callback query. Legacy treats a failure here as fatal, so
     * callers that must not break get the swallowing variant below.
     *
     * @throws TelegramApiException
     */
    public function answerCallbackQuery(string $callbackId, string $text, bool $showAlert = true): array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $showAlert,
        ]);
    }

    /**
     * Acknowledge a callback query without letting a failure escape.
     *
     * Telegram rejects callback queries older than a few minutes; a failure
     * here must never abort the business logic that already ran.
     */
    public function answerCallbackQueryQuietly(?string $callbackId, string $text, bool $showAlert = true): void
    {
        if ($callbackId === null || $callbackId === '') {
            return;
        }

        try {
            $this->answerCallbackQuery($callbackId, $text, $showAlert);
        } catch (TelegramApiException $e) {
            Log::warning('Telegram answerCallbackQuery failed.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ---------------------------------------------------------------------
    // Bot metadata
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     *
     * @throws TelegramApiException
     */
    public function getMe(): ?array
    {
        return $this->result('getMe');
    }

    /**
     * Membership status of a user inside a chat.
     *
     * Legacy `checkUserChannelJoin()` treats member, administrator and creator
     * as joined and everything else (including `left` and `restricted`) as not
     * joined.
     */
    public function isChatMember(int|string $chatId, int|string $userId): bool
    {
        $result = $this->result('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);

        $status = $result['status'] ?? null;

        return in_array($status, ['member', 'administrator', 'creator'], true);
    }

    // ---------------------------------------------------------------------
    // Webhook management
    // ---------------------------------------------------------------------

    /**
     * Register the webhook, replacing any previously configured one.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws TelegramApiException
     */
    public function setWebhook(string $url, array $options = []): array
    {
        return $this->call('setWebhook', array_merge(['url' => $url], $options));
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws TelegramApiException
     */
    public function getWebhookInfo(): ?array
    {
        return $this->result('getWebhookInfo');
    }

    /**
     * @throws TelegramApiException
     */
    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook');
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Perform the request and unwrap the Telegram envelope.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws TelegramApiException
     */
    private function request(string $method, array $params): array
    {
        $token = config('echovpn.telegram.token');

        if (blank($token)) {
            throw new TelegramApiException('Telegram bot token is not configured.');
        }

        try {
            $response = $this->client()
                ->post($this->baseUrl($token).'/'.$method, $this->body($params));
        } catch (ConnectionException $e) {
            Log::error('Telegram transport failure.', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            throw TelegramApiException::transport($method, $e->getMessage());
        }

        return $this->decode($method, $response);
    }

    /**
     * Multipart requests are used when a file has to be uploaded.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|string
     */
    private function body(array $params): array|string
    {
        foreach ($params as $value) {
            if (is_resource($value)) {
                return $this->asMultipart($params);
            }
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function asMultipart(array $params): array
    {
        $multipart = [];

        foreach ($params as $key => $value) {
            // Telegram expects markup and other composite values as JSON strings.
            $multipart[] = is_array($value)
                ? [$key => json_encode($value, JSON_UNESCAPED_UNICODE)]
                : [$key => $value];
        }

        return $multipart;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TelegramApiException
     */
    private function decode(string $method, Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            $exception = new TelegramApiException(
                sprintf('Telegram API call [%s] returned a malformed response.', $method),
                Str::limit($response->body(), 300)
            );

            $this->logFailure($method, $exception);

            throw $exception;
        }

        if (($payload['ok'] ?? false) !== true) {
            $exception = TelegramApiException::fromResponse($method, $payload);

            $this->logFailure($method, $exception);

            throw $exception;
        }

        return $payload;
    }

    private function logFailure(string $method, TelegramApiException $exception): void
    {
        Log::error('Telegram API call failed.', [
            'method' => $method,
            'error_code' => $exception->errorCode(),
            'description' => $exception->description(),
        ]);
    }

    private function client(): PendingRequest
    {
        return Http::asJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT);
    }

    private function baseUrl(string $token): string
    {
        return self::BASE_URL.'/bot'.$token;
    }
}
