<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The Telegram webhook is a server-to-server POST authenticated by the
        // X-Telegram-Bot-Api-Secret-Token header, so a CSRF token cannot exist.
        // The bank SMS gateway is another server-to-server POST with no shared
        // secret, matching legacy `bank/sms.php`, so it is excluded as well.
        $middleware->validateCsrfTokens(except: [
            'telegram/*',
            'bank/*',
        ]);

        $middleware->alias([
            'telegram.webhook' => \App\Http\Middleware\VerifyTelegramWebhook::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
