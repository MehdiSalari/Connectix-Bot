<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the `X-Telegram-Bot-Api-Secret-Token` header that Telegram sends
 * with every webhook delivery.
 *
 * The legacy application defined a webhook secret in config.php but never
 * checked it, leaving the endpoint open to forged updates.
 */
class VerifyTelegramWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('echovpn.telegram.webhook_secret');

        if (blank($expected)) {
            return $next($request);
        }

        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (! hash_equals((string) $expected, $provided)) {
            return response()->json(['ok' => false], 403);
        }

        return $next($request);
    }
}
