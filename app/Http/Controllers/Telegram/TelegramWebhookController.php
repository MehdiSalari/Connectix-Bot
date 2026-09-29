<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Models\HandledUpdate;
use App\Services\Telegram\TelegramGateway;
use App\Telegram\TelegramUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram webhook entry point.
 *
 * Replaces bot.php. The controller only decodes the request and hands it to the
 * gateway; every decision lives in services and actions. Any failure is
 * contained so a single bad update can never take the bot offline.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramGateway $gateway,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return $this->ok();
        }

        $update = TelegramUpdate::fromArray($payload);

        // Telegram retries any delivery it could not confirm, and legacy had no
        // way to notice: a retry re-ran the whole handler chain and could write
        // a second order. The update id is claimed first, so a redelivery is
        // acknowledged and dropped instead of processed twice.
        if (! $this->claim($update->updateId())) {
            return $this->ok();
        }

        try {
            $this->gateway->handle($update);
        } catch (Throwable $e) {
            // The webhook must acknowledge the delivery even when handling
            // failed, otherwise Telegram retries it in a tight loop.
            Log::error('Telegram update handling failed.', [
                'update_id' => $update->updateId(),
                'chat_id' => $update->chatId(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->ok();
    }

    /**
     * Record the update id and report whether it is new.
     *
     * A ledger that cannot be written is logged and then ignored, because the
     * alternative - refusing every update - takes the whole bot offline. The
     * only consequence is that a Telegram retry is processed a second time,
     * which is the behaviour legacy had anyway.
     */
    private function claim(int $updateId): bool
    {
        try {
            return HandledUpdate::claim($updateId);
        } catch (Throwable $e) {
            Log::error('Could not record the Telegram update id; deduplication is off.', [
                'update_id' => $updateId,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    private function ok(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
