<?php

use App\Http\Middleware\EnsureInstalled;
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

        /*
         * First-run detection. Legacy had no such thing: a half configured
         * deployment answered Telegram updates and threw a database error in the
         * PHP log. This is the one place the "is this installed?" question is
         * asked, and it is asked before the request reaches any application code,
         * so an uninstalled application sends the operator to /setup instead of
         * failing at runtime.
         *
         * It is prepended rather than appended so the installer itself never
         * depends on configuration it has not written yet.
         */
        $middleware->prepend(EnsureInstalled::class);

        $middleware->alias([
            'telegram.webhook' => \App\Http\Middleware\VerifyTelegramWebhook::class,
            'admin.auth' => \App\Http\Middleware\AuthenticateAdmin::class,
            'admin.role' => \App\Http\Middleware\EnsureAdminRole::class,
            'setup.protect' => \App\Http\Middleware\ProtectSetup::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
