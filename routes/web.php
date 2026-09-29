<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Bank\BankSmsController;
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

/*
|--------------------------------------------------------------------------
| Bank SMS webhook
|--------------------------------------------------------------------------
|
| Port of bank/sms.php: the bank gateway posts the raw transfer SMS here, and
| it is stored for the SMS auto-payment matching. Like the legacy endpoint it
| carries no secret, so it is exempt from CSRF verification.
|
*/

Route::post('/bank/sms', BankSmsController::class)->name('bank.sms');

/*
|--------------------------------------------------------------------------
| Admin panel authentication
|--------------------------------------------------------------------------
|
| Port of login.php / logout.php and the session check that guarded every
| legacy panel page. The web middleware group brings the session and CSRF
| protection: only the login page is public, everything behind it needs the
| admin guard, and the dashboard is the placeholder the next phases fill.
|
*/

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('login', [AdminLoginController::class, 'show'])->name('login');
    Route::post('login', [AdminLoginController::class, 'login'])->name('login.attempt');

    Route::middleware('admin.auth')->group(function (): void {
        Route::post('logout', [AdminLoginController::class, 'logout'])->name('logout');

        Route::get('/', AdminDashboardController::class)
            ->middleware('admin.role:admin,editor')
            ->name('dashboard');
    });
});
