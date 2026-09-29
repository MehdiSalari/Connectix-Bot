<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Broadcast\BroadcastService;
use App\Services\Panel\PanelSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The broadcast page: compose a message, optionally attach media, and stream
 * the fan-out over Server-Sent Events exactly like the legacy
 * broadcast/broadcast_progress.php did.
 *
 * Test mode delivers only to the admin's own chat id so a reseller can check
 * the copy and media once before releasing it to every user.
 */
class AdminBroadcastController extends Controller
{
    /**
     * What a broadcast may attach: the sniffed content type has to be an image
     * or an MP4, the extension has to be one Telegram will render, and the file
     * has to stay under ten megabytes.
     *
     * `application/mp4` sits beside `video/mp4` because that is what Symfony's
     * extension table reports for an .mp4 name, while finfo on the bytes of a
     * real upload says `video/mp4`. Both are legitimate spellings of the same
     * container, and accepting either keeps the rule honest on every platform.
     */
    private const MEDIA_RULES = [
        'nullable',
        'file',
        'mimes:jpg,jpeg,png,gif,webp,mp4',
        'mimetypes:image/jpeg,image/png,image/gif,image/webp,video/mp4,application/mp4',
        'max:10240',
    ];

    public function __construct(
        private readonly BroadcastService $broadcast,
    ) {}

    public function show(): View
    {
        return view('admin.broadcast.index', [
            'appName' => app(PanelSettingsService::class)->appName(),
            'running' => $this->broadcast->pending() !== null,
            'sent' => $this->broadcast->pending() === null ? null : $this->broadcast->progress(),
        ]);
    }

    /**
     * Validate and either send a test message or start a real broadcast.
     *
     * Mirrors broadcast_start.php: the media file, if any, is parked next to
     * the job state, a test is delivered to the administrator's own chat right
     * away and its media deleted, and a real run is persisted for the progress
     * stream to pick up.
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4096'],
            'test' => ['sometimes', 'boolean'],
            'media' => self::MEDIA_RULES,
        ]);

        /** @var Admin $admin */
        $admin = $request->user('admin');

        $media = null;

        $upload = $request->file('media');

        if ($upload instanceof UploadedFile && $upload->isValid()) {
            // The name is the server's own: the client's filename would decide
            // the extension of whatever lands in storage.
            $name = Str::random(24).'.'.($upload->guessExtension() ?? 'bin');

            $media = $this->broadcast->mediaStoragePath().'/'.$name;

            $upload->move($this->broadcast->mediaStoragePath(), $name);
        }

        if ($request->boolean('test')) {
            [$ok, $error] = $this->broadcast->sendTest(
                (string) $data['message'],
                $media,
                (string) $admin->chat_id,
            );

            return response()->json([
                'success' => $ok,
                'test' => true,
                'message' => $ok ? 'تست با موفقیت ارسال شد!' : 'خطا در ارسال تست',
                'description' => $error,
            ]);
        }

        $this->broadcast->persist(
            (string) $data['message'],
            $media,
            (string) $admin->chat_id,
            false,
        );

        return response()->json([
            'ok' => true,
            'progress_url' => route('admin.broadcast.progress'),
        ]);
    }

    /**
     * The Server-Sent Events stream that runs the send loop.
     *
     * Each recipient produces a `log` event, the loop emits empty `progress`
     * events, and the final `done` event carries the totals. The stream ends
     * with the job cleaned up, so a refresh of the page is ready for the next
     * run.
     */
    public function progress(Request $request): StreamedResponse
    {
        // SameSite does not protect a plain GET, and this stream is the thing
        // that runs the fan-out: a page on another origin must be refused
        // before the loop starts. The header is absent on older browsers, where
        // the admin-role check on the route and the session cookie are what
        // remain.
        $fetchSite = $request->header('Sec-Fetch-Site');

        if ($fetchSite !== null && $fetchSite !== 'same-origin' && $fetchSite !== 'none') {
            abort(403);
        }

        return response()->stream(function () use ($request): void {
            $this->streamHeaders();

            $withSecret = $request->boolean('with_secret');

            $this->broadcast->run(
                function (string $chatId, bool $ok, string $error, int $done, int $total) use ($withSecret): void {
                    $this->event([
                        'type' => 'log',
                        'chat_id' => $withSecret ? $chatId : $this->mask($chatId),
                        'status' => $ok ? 'success' : 'error',
                        'message' => $ok ? 'ارسال شد' : ($error !== '' ? $error : 'خطا'),
                    ]);

                    $this->event(['type' => 'progress', 'done' => $done, 'total' => $total]);
                },
                function (int $total): void {
                    $this->event(['type' => 'done', 'total' => $total]);
                },
            );
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function streamHeaders(): void
    {
        if (function_exists('ob_end_flush')) {
            @ob_end_flush();
        }

        if (function_exists('ob_implicit_flush')) {
            @ob_implicit_flush(true);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(array $payload): void
    {
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";

        if (function_exists('flush')) {
            @flush();
        }
    }

    /**
     * Obscure everything but the last three digits, like the legacy browser
     * log did when it truncated ids. The full id stays available through the
     * `with_secret` query flag.
     */
    private function mask(string $chatId): string
    {
        $length = strlen($chatId);

        return $length > 4
            ? str_repeat('*', $length - 3).substr($chatId, -3)
            : $chatId;
    }
}
