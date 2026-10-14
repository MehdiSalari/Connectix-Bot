<?php

use App\Http\Controllers\Admin\AdminAccountController;
use App\Http\Controllers\Admin\AdminActivityController;
use App\Http\Controllers\Admin\AdminAdminsController;
use App\Http\Controllers\Admin\AdminAppearanceController;
use App\Http\Controllers\Admin\AdminBotButtonController;
use App\Http\Controllers\Admin\AdminBotEmojiController;
use App\Http\Controllers\Admin\AdminBroadcastController;
use App\Http\Controllers\Admin\AdminCardController;
use App\Http\Controllers\Admin\AdminClientController;
use App\Http\Controllers\Admin\AdminCouponController;
use App\Http\Controllers\Admin\AdminCryptoController;
use App\Http\Controllers\Admin\AdminCustomPlanController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFeaturesController;
use App\Http\Controllers\Admin\AdminFlashSaleController;
use App\Http\Controllers\Admin\AdminGatewayController;
use App\Http\Controllers\Admin\AdminGuideController;
use App\Http\Controllers\Admin\AdminLocalServiceController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminLoyaltyController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminOrderDetailsController;
use App\Http\Controllers\Admin\AdminPanelNodeController;
use App\Http\Controllers\Admin\AdminResellerController;
use App\Http\Controllers\Admin\AdminSecurityController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminSmsPaymentController;
use App\Http\Controllers\Admin\AdminTicketController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWalletTransactionController;
use App\Http\Controllers\Admin\AdminWinbackController;
use App\Http\Controllers\Bank\BankSmsController;
use App\Http\Controllers\Pay\GatewayCallbackController;
use App\Http\Controllers\Setup\SetupWizardController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // If you whant to have a welcome page, add it here
    return redirect()->route('admin.dashboard');
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

Route::post('/bank/sms', BankSmsController::class)
    ->middleware('throttle:60,1')
    ->name('bank.sms');

/*
| Online gateway return + IPN
|
| Port of pay/callback.php: the browser returns here after paying (GET) and
| NOWPayments posts its IPN to the same URL (POST, CSRF-exempt like the bank
| webhook — the endpoint trusts nothing beyond the secret order token).
|
*/

Route::match(['get', 'post'], '/pay/gateway/callback', GatewayCallbackController::class)
    ->name('pay.gateway.callback');

/*
|--------------------------------------------------------------------------
| Installation wizard
|--------------------------------------------------------------------------
|
| The Laravel replacement for legacy setup/index.php, setup/setup.php and
| setup/setup_progress.php.
|
| Two middlewares decide what happens here:
|
|  - `setup.installed` (EnsureInstalled, global) is what sends an uninstalled
|    application here in the first place. It lets /setup and /up through even on a
|    fresh deployment, which is the only reason a fresh deployment can be
|    installed at all;
|  - `setup.protect` keeps the wizard to a signed in administrator once the
|    application is installed, and answers 404 to everyone else.
|
| The steps are the legacy order, split so each can be retried on its own. The
| POST routes are throttled: an installer that creates an admin and registers a
| webhook should not be something a script can hammer.
|
*/

// The import step polls this while its own long POST is still running, so it
// sits outside the throttled group below: throttle:30,1 would 429 the reader.
Route::prefix('setup')->name('setup.')->middleware(['setup.protect'])->group(function (): void {
    Route::get('/import/progress', [SetupWizardController::class, 'importProgress'])->name('import.progress');
});

Route::prefix('setup')->name('setup.')->middleware(['setup.protect', 'throttle:30,1'])->group(function (): void {
    Route::get('/', [SetupWizardController::class, 'index'])->name('index');
    Route::get('/done', [SetupWizardController::class, 'done'])->name('done');

    Route::post('/database', [SetupWizardController::class, 'database'])->name('database');
    Route::post('/migrations', [SetupWizardController::class, 'migrations'])->name('migrations');
    Route::post('/connectix', [SetupWizardController::class, 'connectix'])->name('connectix');
    Route::post('/telegram', [SetupWizardController::class, 'telegram'])->name('telegram');
    Route::post('/webhook', [SetupWizardController::class, 'webhook'])->name('webhook');
    Route::post('/admin', [SetupWizardController::class, 'admin'])->name('admin');
    Route::post('/bot-config', [SetupWizardController::class, 'botConfig'])->name('bot-config');
    Route::post('/import', [SetupWizardController::class, 'import'])->name('import');
    Route::post('/complete', [SetupWizardController::class, 'complete'])->name('complete');
    Route::post('/reset', [SetupWizardController::class, 'reset'])->name('reset');

    // Last, so it never shadows a named step above.
    Route::get('/{step}', [SetupWizardController::class, 'show'])->name('show');
});

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
    Route::post('login', [AdminLoginController::class, 'login'])
        ->middleware('throttle:admin-login')
        ->name('login.attempt');

    /*
     * The TOTP second step (fork: verify_2fa.php + cancel_2fa.php). A
     * totp_enabled admin who passed the password never opens a session —
     * login() parks them here with only a pending_admin_id marker; the
     * verified code (or a one-time backup code) completes the login, and
     * cancel drops the pending marker back to the login form. Public by
     * design, like login itself, and the controller throttles failures.
     */
    Route::match(['get', 'post'], 'login/2fa', [AdminLoginController::class, 'verify2fa'])
        ->name('login.2fa');
    Route::get('login/2fa/cancel', [AdminLoginController::class, 'cancelTwoFactor'])
        ->name('login.2fa.cancel');

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
        // The details modal opens from a row avatar on the list. The key is
        // the chat id, not the local row id, because that is what the list
        // prints and what the Telegram bot identifies a user by.
        Route::get('users/{chatId}/details', [AdminUserController::class, 'details'])
            ->middleware('admin.role:admin,editor')
            ->where('chatId', '[0-9]+')
            ->name('users.details');
        // The wallet history modal, on the profile page and the list. Read
        // only, so an editor may open it too.
        Route::get('users/{chatId}/wallet-history', [AdminUserController::class, 'walletHistory'])
            ->middleware('admin.role:admin,editor')
            ->where('chatId', '[0-9]+')
            ->name('users.wallet-history');
        // One wallet action, not two: the adjustment opens the wallet itself
        // when the user has none yet, so a separate "create wallet" step
        // could only ever be the thing an admin forgot to press first.
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
        // Deletion touches the seller panel as well as the local row, so it is
        // admin only - an editor may read a client but not erase one.
        Route::delete('clients/{client}', [AdminClientController::class, 'destroy'])
            ->middleware('admin.role:admin')
            ->name('clients.destroy');

        // Orders and payment approval
        Route::get('orders', [AdminOrderController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('orders.index');
        Route::post('orders/{payment}/decide', [AdminOrderController::class, 'decide'])
            ->middleware('admin.role:admin')
            ->name('orders.decide');
        // The per-row detail sheet. Read only, so an editor may open it too.
        Route::get('orders/{payment}/details', [AdminOrderDetailsController::class, 'show'])
            ->middleware('admin.role:admin,editor')
            ->name('orders.details');

        // Ledgers
        Route::get('wallet-transactions', [AdminWalletTransactionController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('wallet-transactions.index');
        Route::get('sms-payments', [AdminSmsPaymentController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('sms-payments.index');
        // The per-row deposit sheet: three cards of what the row is plus the
        // purchase card below them. Read only, so an editor may open it too.
        Route::get('sms-payments/{smsPayment}/details', [AdminSmsPaymentController::class, 'details'])
            ->middleware('admin.role:admin,editor')
            ->name('sms-payments.details');

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
        // The stream is what actually runs the send loop, so it carries the
        // same `admin` requirement as the start it consumes: an editor must not
        // be able to fire the job that `broadcast.start` alone may create.
        Route::get('broadcast/progress', [AdminBroadcastController::class, 'progress'])
            ->middleware('admin.role:admin')
            ->name('broadcast.progress');

        // ظاهر و تم: انتخاب تم پیش‌فرض پنل و وب‌اپ. خواندن برای هر دو نقش،
        // نوشتن فقط با ادمین — همان مرزی که بقیهٔ صفحات تنظیمات دارند.
        Route::get('appearance', [AdminAppearanceController::class, 'show'])
            ->middleware('admin.role:admin,editor')
            ->name('appearance');
        Route::post('appearance', [AdminAppearanceController::class, 'store'])
            ->middleware('admin.role:admin')
            ->name('appearance.store');

        /*
         * درگاه‌های پرداخت، پرداخت کریپتو، پلن سفارشی و کد تخفیف: همان مرزی
         * که بقیهٔ صفحات تنظیمات دارند — خواندن برای هر دو نقش، نوشتن فقط با
         * ادمین.
         */

        // درگاه‌های پرداخت آنلاین
        Route::get('gateways', [AdminGatewayController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('gateways.index');
        Route::post('gateways', [AdminGatewayController::class, 'update'])
            ->middleware('admin.role:admin')
            ->name('gateways.update');
        Route::post('gateways/toggle', [AdminGatewayController::class, 'toggle'])
            ->middleware('admin.role:admin')
            ->name('gateways.toggle');

        // پرداخت کریپتو (TRX/TON)
        Route::get('crypto', [AdminCryptoController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('crypto.index');
        Route::post('crypto', [AdminCryptoController::class, 'update'])
            ->middleware('admin.role:admin')
            ->name('crypto.update');

        // پلن سفارشی (حجم و زمان دلخواه)
        Route::get('custom-plan', [AdminCustomPlanController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('custom-plan.index');
        Route::post('custom-plan', [AdminCustomPlanController::class, 'update'])
            ->middleware('admin.role:admin')
            ->name('custom-plan.update');

        // کد تخفیف محلی
        Route::get('coupons', [AdminCouponController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('coupons.index');
        Route::post('coupons', [AdminCouponController::class, 'store'])
            ->middleware('admin.role:admin')
            ->name('coupons.store');
        Route::post('coupons/toggle', [AdminCouponController::class, 'toggle'])
            ->middleware('admin.role:admin')
            ->name('coupons.toggle');
        Route::post('coupons/delete', [AdminCouponController::class, 'destroy'])
            ->middleware('admin.role:admin')
            ->name('coupons.destroy');

        /*
         * نمایندگی‌ها: the reseller management page. Reading is open to both
         * roles; every mutating action (settings, quota, reset, discount,
         * approve/reject) is admin-only, the same boundary the rest of the
         * panel draws.
         */
        Route::get('resellers', [AdminResellerController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('resellers.index');
        Route::post('resellers/settings', [AdminResellerController::class, 'updateSettings'])
            ->middleware('admin.role:admin')
            ->name('resellers.settings');
        Route::post('resellers/quota', [AdminResellerController::class, 'quota'])
            ->middleware('admin.role:admin')
            ->name('resellers.quota');
        Route::post('resellers/reset-used', [AdminResellerController::class, 'resetUsed'])
            ->middleware('admin.role:admin')
            ->name('resellers.reset-used');
        Route::post('resellers/discount', [AdminResellerController::class, 'discount'])
            ->middleware('admin.role:admin')
            ->name('resellers.discount');
        Route::post('resellers/decide', [AdminResellerController::class, 'decide'])
            ->middleware('admin.role:admin')
            ->name('resellers.decide');

        /*
         * کارت‌های مشکوک: the suspicious-cards page. Reading is open to both
         * roles; the alert threshold is admin-only.
         */
        Route::get('cards', [AdminCardController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('cards.index');
        Route::post('cards/threshold', [AdminCardController::class, 'threshold'])
            ->middleware('admin.role:admin')
            ->name('cards.threshold');

        /*
         * حراج زماندار، سیستم امتیازدهی، یادآور انقضا، بازگشت مشتری و
         * ویژگیهای ویژه: the five fork feature pages. Reading is open to both
         * roles; every mutation (save/toggle/delete/queue/backup) is admin-only
         * — the same boundary the rest of the settings pages draw.
         */
        Route::get('flash-sales', [AdminFlashSaleController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('flash-sales.index');
        Route::post('flash-sales', [AdminFlashSaleController::class, 'index'])
            ->middleware('admin.role:admin')
            ->name('flash-sales.store');

        Route::get('loyalty', [AdminLoyaltyController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('loyalty.index');
        Route::post('loyalty', [AdminLoyaltyController::class, 'index'])
            ->middleware('admin.role:admin')
            ->name('loyalty.store');

        Route::get('notifications', [AdminNotificationController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('notifications.index');
        Route::post('notifications', [AdminNotificationController::class, 'index'])
            ->middleware('admin.role:admin')
            ->name('notifications.store');

        Route::get('winback', [AdminWinbackController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('winback.index');
        Route::post('winback', [AdminWinbackController::class, 'index'])
            ->middleware('admin.role:admin')
            ->name('winback.store');

        Route::get('features', [AdminFeaturesController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('features.index');
        Route::post('features', [AdminFeaturesController::class, 'index'])
            ->middleware('admin.role:admin')
            ->name('features.store');

        /*
         * The two leftover fork sections, now real: the account page (own
         * email/password — GET renders the form, POST applies the change
         * under the same name, since the sidebar and the flash redirect both
         * address `account.show`) and the activity trail. Each admin sees
         * only their own rows — the fork's rule: «هر ادمین فقط فعالیتهای
         * خودش را میبیند».
         */
        Route::match(['get', 'post'], 'account', [AdminAccountController::class, '__invoke'])
            ->middleware('admin.role:admin,editor')
            ->name('account.show');

        Route::get('activity', [AdminActivityController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('activity.index');

        /*
         * امنیت حساب من — self-service TOTP 2FA (fork: pages/admin/security.php
         * + services/TwoFactorAuth.php). GET renders the status/setup form;
         * POST dispatches start_setup / confirm_setup / disable /
         * regenerate_backup through the controller's __invoke. Sits with the
         * other self-service account rows it is grouped with in the sidebar.
         */
        Route::match(['get', 'post'], 'security', AdminSecurityController::class)
            ->middleware('admin.role:admin,editor')
            ->name('security.show');

        /*
         * دکمه‌های صفحه اصلی: three screen tabs (main menu board with the
         * row/column layout, buy screen, OS picker), the per-row inline edit,
         * the board's reorder save and the default-colour card. Listing is
         * open to both roles; every mutation is admin-only — the same boundary
         * the rest of the settings pages draw.
         */
        Route::get('buttons', [AdminBotButtonController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('buttons.index');
        Route::post('buttons/reorder', [AdminBotButtonController::class, 'reorder'])
            ->middleware('admin.role:admin')
            ->name('buttons.reorder');
        Route::post('buttons/default-color', [AdminBotButtonController::class, 'defaultColor'])
            ->middleware('admin.role:admin')
            ->name('buttons.default-color');
        Route::post('buttons/toggle', [AdminBotButtonController::class, 'toggle'])
            ->middleware('admin.role:admin')
            ->name('buttons.toggle');
        Route::post('buttons', [AdminBotButtonController::class, 'update'])
            ->middleware('admin.role:admin')
            ->name('buttons.update');

        /*
         * ایموجی‌های پرمیوم: the emoji registry with its section tabs, the
         * per-emoji edit rows (fallback char + premium custom_emoji_id), the
         * import-pack form and the favourites star. Read for both roles;
         * writes admin-only.
         */
        Route::get('emojis', [AdminBotEmojiController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('emojis.index');
        Route::post('emojis/import-pack', [AdminBotEmojiController::class, 'importPack'])
            ->middleware('admin.role:admin')
            ->name('emojis.import-pack');
        Route::post('emojis/favorite', [AdminBotEmojiController::class, 'toggleFavorite'])
            ->middleware('admin.role:admin')
            ->name('emojis.favorite');
        Route::post('emojis/delete-pack', [AdminBotEmojiController::class, 'destroyPack'])
            ->middleware('admin.role:admin')
            ->name('emojis.delete-pack');
        Route::post('emojis/delete', [AdminBotEmojiController::class, 'destroy'])
            ->middleware('admin.role:admin')
            ->name('emojis.destroy');
        Route::post('emojis', [AdminBotEmojiController::class, 'update'])
            ->middleware('admin.role:admin')
            ->name('emojis.update');

        /*
         * پنل‌های VPN و پلن‌ها: Marzban / 3x-ui / Remnawave / Hiddify nodes,
         * their connection test (driver testConnection, Http::fake in tests)
         * and the per-panel local plans. The encrypted secret never leaves the
         * service: the edit form only ever sees a has_secret flag. Reading is
         * open to both roles; panels, plans and the test button are admin-only.
         */
        Route::get('panels', [AdminPanelNodeController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('panels.index');
        Route::post('panels/save', [AdminPanelNodeController::class, 'savePanel'])
            ->middleware('admin.role:admin')
            ->name('panels.save');
        Route::post('panels/delete', [AdminPanelNodeController::class, 'deletePanel'])
            ->middleware('admin.role:admin')
            ->name('panels.delete');
        Route::post('panels/test', [AdminPanelNodeController::class, 'testPanel'])
            ->middleware('admin.role:admin')
            ->name('panels.test');
        Route::post('panels/plan/save', [AdminPanelNodeController::class, 'savePlan'])
            ->middleware('admin.role:admin')
            ->name('panels.plan.save');
        Route::post('panels/plan/delete', [AdminPanelNodeController::class, 'deletePlan'])
            ->middleware('admin.role:admin')
            ->name('panels.plan.delete');

        /*
         * کاربران و سرویس‌ها (مستقل از Connectix): hand-made users keyed by
         * their Telegram chat id and the local services rows. Wave 3 scope:
         * everything operates on local rows only; remote provisioning on the
         * VPN panel arrives with wave 4. Search and listing are open to both
         * roles; every mutation is admin-only.
         */
        Route::get('localservices', [AdminLocalServiceController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('localservices.index');
        Route::get('localservices/users/search', [AdminLocalServiceController::class, 'searchUsers'])
            ->middleware('admin.role:admin,editor')
            ->name('localservices.users.search');
        Route::post('localservices/users', [AdminLocalServiceController::class, 'createUser'])
            ->middleware('admin.role:admin')
            ->name('localservices.users.store');
        Route::post('localservices/users/block', [AdminLocalServiceController::class, 'setBlocked'])
            ->middleware('admin.role:admin')
            ->name('localservices.users.block');
        Route::post('localservices/services', [AdminLocalServiceController::class, 'createService'])
            ->middleware('admin.role:admin')
            ->name('localservices.services.store');
        Route::post('localservices/services/attach', [AdminLocalServiceController::class, 'attach'])
            ->middleware('admin.role:admin')
            ->name('localservices.services.attach');
        Route::post('localservices/services/renew', [AdminLocalServiceController::class, 'renew'])
            ->middleware('admin.role:admin')
            ->name('localservices.services.renew');
        Route::post('localservices/services/enabled', [AdminLocalServiceController::class, 'setEnabled'])
            ->middleware('admin.role:admin')
            ->name('localservices.services.enabled');
        Route::post('localservices/services/reset-traffic', [AdminLocalServiceController::class, 'resetTraffic'])
            ->middleware('admin.role:admin')
            ->name('localservices.services.reset-traffic');
        Route::post('localservices/services/delete', [AdminLocalServiceController::class, 'destroy'])
            ->middleware('admin.role:admin')
            ->name('localservices.services.destroy');

        /*
         * تیکت‌ها: لیست و صفحات خواندن برای هر دو نقش، نوشتن فقط با ادمین —
         * همان مرزی که بقیهٔ صفحات دارند و همان نقش‌بندی فورک (پشتیبان فقط
         * می‌خواند). reply/status/close هر سه POST هستند و هر سه در
         * admin_activity ثبت می‌شوند.
         */
        Route::get('tickets', [AdminTicketController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('tickets.index');
        Route::get('tickets/{ticket}', [AdminTicketController::class, 'show'])
            ->middleware('admin.role:admin,editor')
            ->name('tickets.show');
        Route::post('tickets/{ticket}/reply', [AdminTicketController::class, 'reply'])
            ->middleware('admin.role:admin')
            ->name('tickets.reply');
        Route::post('tickets/{ticket}/status', [AdminTicketController::class, 'status'])
            ->middleware('admin.role:admin')
            ->name('tickets.status');
        Route::post('tickets/{ticket}/close', [AdminTicketController::class, 'close'])
            ->middleware('admin.role:admin')
            ->name('tickets.close');

        /*
         * ادمین‌ها: مدیریت حساب‌های پنل. خواندن برای هر دو نقش؛ ساخت،
         * فعال/غیرفعال، دسترسی‌ها و تغییر رمز فقط با ادمین. حساب ادمین اصلی
         * (نصب) در هیچ مسیری قابل تغییر نیست و ادیتور اصلاً به این صفحات
         * نوشتن راه ندارد.
         */
        Route::get('admins', [AdminAdminsController::class, 'index'])
            ->middleware('admin.role:admin,editor')
            ->name('admins.index');
        Route::post('admins', [AdminAdminsController::class, 'store'])
            ->middleware('admin.role:admin')
            ->name('admins.store');
        Route::post('admins/{admin}/toggle', [AdminAdminsController::class, 'toggle'])
            ->middleware('admin.role:admin')
            ->name('admins.toggle');
        Route::post('admins/{admin}/permissions', [AdminAdminsController::class, 'updatePermissions'])
            ->middleware('admin.role:admin')
            ->name('admins.permissions');
        Route::post('admins/{admin}/password', [AdminAdminsController::class, 'resetPassword'])
            ->middleware('admin.role:admin')
            ->name('admins.password');
    });
});
