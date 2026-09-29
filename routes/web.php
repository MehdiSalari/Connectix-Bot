<?php

use App\Http\Controllers\Admin\AdminBroadcastController;
use App\Http\Controllers\Admin\AdminClientController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminGuideController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminSmsPaymentController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWalletTransactionController;
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
| protection: only the login page is public, and everything behind the login
| requires the admin guard.
|
| Role gating is an explicit improvement over legacy: legacy checked only that
| an `admin_id` session existed, so an editor could change every setting.
| Here, listing pages are open to both roles while mutating actions (wallet
| changes, order decisions, settings, guides, broadcast) require `admin`.
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

        // Users
        Route::get('users', [AdminUserController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('users.index');
        Route::get('users/search', [AdminUserController::class, 'search'])
            ->middleware('admin.role:admin,editor')
            ->name('users.search');
        Route::post('users/wallet/create', [AdminUserController::class, 'createWallet'])
            ->middleware('admin.role:admin')
            ->name('users.wallet.create');
        Route::post('users/wallet/adjust', [AdminUserController::class, 'adjustWallet'])
            ->middleware('admin.role:admin')
            ->name('users.wallet.adjust');
        Route::get('users/{user}', [AdminUserController::class, 'show'])
            ->middleware('admin.role:admin,editor')
            ->name('users.show');

        // Connectix client details
        Route::get('clients/{client}', [AdminClientController::class, 'show'])
            ->middleware('admin.role:admin,editor')
            ->name('clients.show');

        // Orders and payment approval
        Route::get('orders', [AdminOrderController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('orders.index');
        Route::post('orders/{payment}/decide', [AdminOrderController::class, 'decide'])
            ->middleware('admin.role:admin')
            ->name('orders.decide');

        // Ledgers
        Route::get('wallet-transactions', [AdminWalletTransactionController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('wallet-transactions.index');
        Route::get('sms-payments', [AdminSmsPaymentController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('sms-payments.index');

        // Bot configuration
        Route::get('settings', [AdminSettingsController::class, 'show'])
            ->middleware('admin.role:admin,editor')
            ->name('settings.show');
        Route::post('settings', [AdminSettingsController::class, 'update'])
            ->middleware('admin.role:admin')
            ->name('settings.update');

        // Guides
        Route::get('guides', [AdminGuideController::class, 'show'])
            ->middleware('admin.role:admin,editor')
            ->name('guides.index');
        Route::post('guides', [AdminGuideController::class, 'store'])
            ->middleware('admin.role:admin')
            ->name('guides.store');
        Route::post('guides/delete', [AdminGuideController::class, 'destroy'])
            ->middleware('admin.role:admin')
            ->name('guides.destroy');

        // Broadcast
        Route::get('broadcast', [AdminBroadcastController::class, 'show'])
            ->middleware('admin.role:admin,editor')
            ->name('broadcast.show');
        Route::post('broadcast', [AdminBroadcastController::class, 'start'])
            ->middleware('admin.role:admin')
            ->name('broadcast.start');
        Route::get('broadcast/progress', [AdminBroadcastController::class, 'progress'])
            ->middleware('admin.role:admin,editor')
            ->name('broadcast.progress');
    });
});
