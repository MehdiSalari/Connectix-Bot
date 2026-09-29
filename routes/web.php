<?php

use App\Http\Controllers\Telegram\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Telegram webhook
|--------------------------------------------------------------------------
|
| The single entry point for the bot. Replaces bot.php, which Telegram polled
| with getUpdates on every request. The route carries the webhook secret check
| and is exempt from CSRF verification because the request is authenticated by
| the X-Telegram-Bot-Api-Secret-Token header instead of a session.
|
*/

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware('telegram.webhook')
    ->name('telegram.webhook');
