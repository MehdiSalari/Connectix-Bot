<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

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
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return $this->ok();
        }

        $update = TelegramUpdate::fromArray($payload);

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

    private function ok(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
