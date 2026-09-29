<?php

declare(strict_types=1);

namespace App\Services\Broadcast;

use App\Exceptions\TelegramApiException;
use App\Models\User;
use App\Services\Telegram\TelegramService;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Fan-out messaging to every bot user.
 *
 * Port of `broadcast/broadcast_start.php` and `broadcast/broadcast_progress.php`.
 * Legacy stored the pending job in two files next to the code and streamed the
 * send loop over a Server-Sent Events connection; this service keeps the same
 * shared-hosting-friendly design with the same two files, moved to storage:
 *
 *  - `broadcast_start.json` holds the job (message, optional media, target);
 *  - `broadcast_done.stamp` is the count of recipients already sent to, so an
 *    interrupted stream can be resumed instead of re-sending.
 *
 * There is deliberately no queue or worker: like legacy, the request that opens
 * the progress stream performs the sends itself, throttled to a few per second
 * so Telegram never rejects the fan-out.
 */
class BroadcastService
{
    private const STATE_DIR = 'broadcast';

    private const PAYLOAD_FILE = 'broadcast_start.json';

    private const COUNTER_FILE = 'broadcast_done.stamp';

    public function __construct(
        private readonly TelegramService $telegram,
    ) {}

    /**
     * Every recipient Telegram chat id, newest last.
     *
     * @return list<string>
     */
    public function targets(): array
    {
        return User::query()
            ->whereNotNull('chat_id')
            ->pluck('chat_id')
            ->map(static fn ($id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();
    }

    /**
     * Store a pending job on disk.
     *
     * When `$test` is true only the admin's own chat id is sent to, mirroring
     * the test branch of `broadcast_start.php`.
     */
    public function persist(
        string $message,
        ?string $mediaPath,
        string|int $adminChatId,
        bool $test = false,
    ): void {
        $this->ensureStateDirectory();

        $this->writeJson(self::PAYLOAD_FILE, [
            'message' => $message,
            'media' => $mediaPath,
            'test' => $test,
            'admin_chat_id' => (string) $adminChatId,
            'started_at' => now()->toDateTimeString(),
        ]);

        File::put($this->statePath(self::COUNTER_FILE), '0');
    }

    /**
     * The pending job, or null when nothing was started.
     *
     * @return array<string, mixed>|null
     */
    public function pending(): ?array
    {
        $payload = $this->readJson(self::PAYLOAD_FILE);

        if (! is_array($payload)) {
            return null;
        }

        $payload['message'] = (string) ($payload['message'] ?? '');
        $payload['test'] = (bool) ($payload['test'] ?? false);
        $payload['admin_chat_id'] = (string) ($payload['admin_chat_id'] ?? '');

        $media = $payload['media'] ?? null;

        $payload['media'] = is_string($media) && $media !== '' ? $media : null;

        return $payload;
    }

    /**
     * How many recipients have been sent to so far.
     *
     * @return int<0, max>
     */
    public function progress(): int
    {
        $path = $this->statePath(self::COUNTER_FILE);

        return (int) (File::exists($path) ? (string) File::get($path) : '0');
    }

    /**
     * Send the message to one chat right now, without touching the job state.
     *
     * Port of the test branch of `broadcast_start.php`: the reseller's own chat
     * receives the message (or a fallback text) and the answer is reported
     * immediately, so a mistake is caught before a real fan-out starts. The
     * media is deleted afterwards, exactly as legacy did.
     *
     * @return array{0: bool, 1: string} Whether Telegram accepted the send, and the error when it did not.
     */
    public function sendTest(string $message, ?string $mediaPath, string $adminChatId): array
    {
        [$ok, $error] = $this->deliver($adminChatId, $message, $mediaPath, 0);

        if ($mediaPath !== null && File::exists($mediaPath)) {
            File::delete($mediaPath);
        }

        return [$ok, $error];
    }

    /**
     * Run the pending job, calling `$onProgress` after each recipient.
     *
     * Resumes from the stored counter, so a connection that dropped mid-way
     * continues where it stopped. Failed recipients do not abort the loop; the
     * error string is handed to the callback so the panel can display it.
     *
     * @param  callable(string $chatId, bool $ok, string $error, int $done, int $total): void  $onProgress
     * @param  callable(int $total): void  $onDone
     */
    public function run(callable $onProgress, callable $onDone): void
    {
        $job = $this->pending();

        if ($job === null) {
            $onDone(0);

            return;
        }

        $message = $job['message'];
        $media = $job['media'];
        $chatIds = $job['test']
            ? [$job['admin_chat_id']]
            : $this->targets();

        $total = count($chatIds);
        $done = $this->progress() ?? 0;
        $delayUs = (int) config('connectix_bot.broadcast.delay_us', 333000);

        foreach ($chatIds as $index => $chatId) {
            if ($index + 1 <= $done) {
                continue;
            }

            [$ok, $error] = $this->deliver($chatId, $message, $media, 0);

            $done = $index + 1;
            File::put($this->statePath(self::COUNTER_FILE), (string) $done);

            if ($delayUs > 0) {
                usleep($delayUs);
            }

            $onProgress($chatId, $ok, $error, $done, $total);
        }

        $onDone($total);
        $this->cleanup($media);
    }

    /**
     * The directory uploads land in, so the start controller can move the
     * media file next to the job state.
     */
    public function mediaStoragePath(): string
    {
        $this->ensureStateDirectory();

        return storage_path('app/'.self::STATE_DIR);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Deliver one message, with or without an attached file.
     *
     * A flood-control answer (HTTP 429 / `parameters.retry_after`) is honoured
     * up to `connectix_bot.broadcast.flood_retry_after_cap` seconds and the
     * recipient is retried, which is what a large install needs to get through
     * Telegram's per-bot rate limit. Legacy slept a fixed fraction of a second
     * and lost any recipient Telegram refused; here the refusal is bounded,
     * retried, and only then reported as a failure.
     *
     * @param  int  $attempt  How many flood answers this recipient already cost.
     * @return array{0: bool, 1: string}
     */
    private function deliver(string $chatId, string $message, ?string $media, int $attempt = 0): array
    {
        try {
            $this->send($chatId, $message, $media);

            return [true, ''];
        } catch (TelegramApiException $e) {
            $wait = $this->floodWait($e, $attempt);

            if ($wait !== null) {
                if ($wait > 0) {
                    usleep($wait * 1_000_000);
                }

                return $this->deliver($chatId, $message, $media, $attempt + 1);
            }

            return [false, $e->description() !== '' && $e->description() !== null
                ? $e->description()
                : $e->getMessage()];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * How long to wait before retrying a flood-controlled recipient, or null
     * when the failure is not flood control or the retries are used up.
     */
    private function floodWait(TelegramApiException $e, int $attempt): ?int
    {
        $retryAfter = $e->retryAfter();

        if ($retryAfter === null && $e->errorCode() !== 429) {
            return null;
        }

        $retries = (int) config('connectix_bot.broadcast.flood_retries', 1);

        if ($attempt >= $retries) {
            return null;
        }

        $cap = (int) config('connectix_bot.broadcast.flood_retry_after_cap', 10);

        return max(0, min($retryAfter ?? $cap, $cap));
    }

    /**
     * The actual Telegram call for one recipient, mirroring the method choice
     * of `broadcast_progress.php`.
     */
    private function send(string $chatId, string $message, ?string $media): void
    {
        $replyMarkup = json_encode(['remove_keyboard' => true]);

        if ($media === null || ! File::exists($media)) {
            $this->telegram->sendMessage($chatId, $message !== '' ? $message : ' ', [
                'parse_mode' => 'HTML',
                'reply_markup' => $replyMarkup,
            ]);

            return;
        }

        [$method, $fileParam] = $this->mediaMethod($media);

        $params = [
            'parse_mode' => 'HTML',
            'reply_markup' => $replyMarkup,
        ];

        if ($message !== '') {
            $params['caption'] = $message;
        }

        $this->telegram->sendMultipart($method, $chatId, $fileParam, $media, $params);
    }

    /**
     * Pick the Telegram method and file parameter for a media file, exactly as
     * legacy did from the extension and MIME type.
     *
     * @return array{0: string, 1: string}
     */
    private function mediaMethod(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = (string) (File::mimeType($path) ?? '');

        if (str_starts_with($mime, 'image/') && $ext !== 'gif') {
            return ['sendPhoto', 'photo'];
        }

        if ($ext === 'gif' || str_starts_with($mime, 'image/gif')) {
            return ['sendAnimation', 'animation'];
        }

        if (str_starts_with($mime, 'video/')) {
            return ['sendVideo', 'video'];
        }

        if (str_starts_with($mime, 'audio/') || $ext === 'ogg') {
            return ['sendVoice', 'voice'];
        }

        return ['sendDocument', 'document'];
    }

    private function ensureStateDirectory(): void
    {
        File::ensureDirectoryExists(storage_path('app/'.self::STATE_DIR));
    }

    private function statePath(string $file): string
    {
        return storage_path('app/'.self::STATE_DIR.'/'.$file);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJson(string $file, array $data): void
    {
        File::put($this->statePath($file), json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $file): ?array
    {
        $path = $this->statePath($file);

        if (! File::exists($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function cleanup(?string $media): void
    {
        File::delete($this->statePath(self::PAYLOAD_FILE));
        File::delete($this->statePath(self::COUNTER_FILE));

        if ($media !== null && File::exists($media)) {
            File::delete($media);
        }
    }
}
