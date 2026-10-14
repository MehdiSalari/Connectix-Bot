# نقشه نگاشت Airoxnet → Connectix (Laravel)

هر قابلیت نسخه قدیمی (`Connectix - feature/` — فقط خواندنی) و جایگزین واقعی آن در
این بازنویسی، با فایل اجرایی و تستی که رفتارش را پین میکند. منبع ترتیب: همین
مستند؛ منبع تیکها: [`feature-port-checklist.md`](feature-port-checklist.md)
(تیک فقط پس از سبز شدن تست آن ردیف زده میشود).

**خواندنیهای فورک هرگز ویرایش نشدند** — همه رفتارها از روی آنها پورت شده‌اند.

---

## Wave 1 — زیرساخت داده

| قابلیت (Airoxnet) | جایگزین Laravel | تست |
|---|---|---|
| جداول فروش/مالی (`resellers`, `payment_cards`, `flash_sales`, `transactions_audit_log`, …) | `database/migrations/2026_10_13_000001_*` | `tests/Feature/FeatureSchemaTest.php` |
| جداول پشتیبانی/مدیریت (`tickets`, `admins` + ستونهای 2FA, `admin_activity`) | `0001_01_01_000000_create_legacy_schema_tables.php`, `2026_10_13_000002_*` | همان |
| جداول ربات (`bot_buttons`, `bot_emojis`, `broadcast_logs`, …) | `2026_10_13_000003_*` | همان |
| جداول درگاه/کریپتو (`payment_gateways`, `gateway_orders`, `crypto_payments`) | `2026_10_13_000004_*` | همان |
| جداول فروشگاه/وفاداری (`loyalty_*`, `panels`, `custom_plan_settings`) | `2026_10_13_000005_*` | همان |
| چرخه حیات اعلان/بازگشت (`notification_settings`, `plan_notifications_sent`, `winback_*`, `welcome_credit_log`) | `2026_10_13_000006_*` | همان |
| ستونهای جدید روی جداول قدیمی (`payments.src_card`, `users.is_blocked`, `clients.expire_date`, …) | `0001_01_01_000000_*` (hasColumn-guarded) | همان |

## Wave 2 — پوسته و منوی Airoxnet

| قابلیت | جایگزین Laravel | تست |
|---|---|---|
| ۸ تم (`theme_service`) | `public/css/connectix.css` + `AdminAppearanceController` (توکن ۸ تم) | `ChromeSkinsTest` |
| صفحه «ظاهر و تم» | `resources/views/admin/appearance/index.blade.php`, `AdminAppearanceController` | `AppearanceTest` |
| منوی گروهی سایدبار + فیلتر نقش | `resources/views/layouts/admin.blade.php` (گروهها، badge تیکت، `Route::has` gating) | بررسی والد: ۲۴ مرجع، صفر روت کمبودی |
| topbar سلام/ساعت تهران/تاریخ شمسی | همان `admin.blade.php` | `ChromeSkinsTest` |
| داشبورد KPI + sparkline + نمودار | `resources/views/admin/dashboard.blade.php` + `AdminDashboardController` | `AdminDashboardChartsTest` |
| «امنیت حساب» + حساب + فعالیتها | `AdminAccountController` (account), **`AdminSecurityController` + `app/Services/Admin/TwoFactorAuth.php` (TOTP خود-خدمتی، پورت `services/TwoFactorAuth.php`)**, `AdminActivityController` | `AdminAccountTest`, **`AdminSecurityTest` (22 تست)**, `AdminAccountTest` (بخش activity) |

## Wave 3 — پنل ادمین

| قابلیت | جایگزین Laravel | تست |
|---|---|---|
| کاربران/سرویسهای مستقل (`localservices`) | `AdminLocalServiceController` + `resources/views/admin/localservices/`, `LocalUserService` | `AdminLocalServicesTest` |
| نمایندگیها | `AdminResellerController` + `admin/resellers/`, `ResellerService` | `AdminResellerTest` |
| تیکتها | `AdminTicketController` + `admin/tickets/`, `Support/TicketService` | `AdminTicketsTest` |
| ادمینها + دسترسی | `AdminAdminsController` + `admin/admins/`, `AdminPermissionService` | `AdminAdminsTest` |
| درگاههای پرداخت | `AdminGatewayController` + `admin/gateways/`, `GatewayConfigService` | `AdminGatewaysTest` |
| پنلها/پلنهای محلی (AES-256-GCM) | `AdminPanelController` + `admin/panels/`, `Panels/PanelNodeService` | `AdminPanelTest`, `AdminPanelNodesTest` |
| پرداخت کریپتو | `AdminCryptoController` + `admin/crypto/`, `Crypto/CryptoConfigService` | `AdminCryptoTest` |
| کد تخفیف | `AdminCouponController` + `admin/coupons/` | `AdminCouponsTest` |
| پلن سفارشی | `AdminCustomPlanController` + `admin/custom-plan/`, `Shop/CustomPlanConfigService` | `AdminCustomPlanTest` |
| حراج زماندار | `AdminFlashSaleController` + `admin/flash-sales/`, `Shop/FlashSaleAdminService` | `AdminFlashSaleTest` |
| امتیازدهی/وفاداری | `AdminLoyaltyController` + `admin/loyalty/`, `Loyalty/LoyaltyAdminService` | `AdminLoyaltyTest` |
| کارتهای مشکوک (آستانه + لیست) | `AdminCardController::suspiciousCards/saveThreshold` + `admin/cards/index.blade.php` (سوییچ `card_track_threshold`) | `AdminCardsTest`, **`AdminCardsSuspiciousTest` (17/17)** |
| دکمههای صفحه اصلی (۳ صفحه/ردیف/ستون) | `AdminButtonsController` + `admin/buttons/`, `Bot/BotButtonService` | `AdminButtonsTest` |
| ایموجیهای پرمیوم | `AdminEmojisController` + `admin/emojis/`, `Bot/EmojiService` | `AdminEmojisTest` |
| یادآور انقضا/مصرف | `AdminNotificationController` + `admin/notifications/`, `Notifications/NotificationAdminService` | `AdminNotificationsTest` |
| بازگشت مشتری (کمپین اجرا/توقف) | `AdminWinbackController` + `admin/winback/`, `Winback/WinbackAdminService` | `AdminWinbackTest` |
| ویژگیها: برند/ایموجی استارت/اعتبار خوشآمد/بکاپ/قفل نصب | `AdminFeaturesController` + `admin/features/`, `Features/*AdminService` | `AdminFeaturesTest` |
| ارسال گروهی/بنر (broadcast) | `AdminBroadcastController` + `admin/broadcast/` | `AdminBroadcastTest` |

## Wave 4 — لایه ربات (Telegram)

| قابلیت | جایگزین Laravel | تست |
|---|---|---|
| پرداخت کریپتو (TRX/TON, نرخ نوبیتکس, تأیید هش) | `Services/Crypto/CryptoPayService.php` + `Telegram/Handlers/CryptoHandler.php` (callback `crypto_*`) | `CryptoPaymentFlowTest`, `CryptoRateServiceTest` |
| درگاه آنلاین (زرینپال/نکستپی/NOWPayments) | `Services/Gateways/OnlineGatewayService.php` + `Telegram/Handlers/GatewayHandler.php` (callback `gw_*`) | `GatewayPaymentFlowTest` |
| روت بازگشت/IPN درگاه | `routes/web.php` → `GET|POST /pay/gateway/callback` (`pay.gateway.callback`) → `Http/Controllers/Pay/GatewayCallbackController.php` (CSRF-exempt) | `GatewayPaymentFlowTest` |
| تیکت در ربات | `Services/Support/TicketService.php` + `Handlers/TicketHandler.php` | `TicketBotFlowTest` |
| نمایندگی در ربات (`repreqok/no`) | `Services/Resellers/ResellerService.php` + `Handlers/ResellerHandler.php` | `ResellerBotFlowTest` |
| وفاداری (امتیاز از پرداخت → کد) | `Services/Shop/LoyaltyBotService.php` + `Handlers/LoyaltyHandler.php` | `LoyaltyBotFlowTest`, `LoyaltyPurchaseTest` |
| پلن سفارشی در ربات | `Services/Shop/CustomPlanBotService.php` + `Handlers/CustomPlanHandler.php` | `CustomPlanBotTest` |
| دکمههای داینامیک + رنگ خودکار | `Services/Bot/KeyboardFactory`-معادل: `Bot/ButtonColorService.php` | `DynamicButtonsTest` |
| حراج زماندار در خرید | `Services/Shop/FlashSaleBotService.php` | `FlashSaleBotTest`, `FlashSalePurchaseTest` |
| شماره کارت پرداختکننده در رسید | ستون `payments.src_card` + نمایش در لیستها | `FeatureSchemaTest` (ستون src_card) |

### کرونها (پورت `cron/*.php` → `routes/console.php`)

| کرون فورک | معادل در اسکدولر | تست |
|---|---|---|
| `cron/check_notifications.php` (هر ۳۰–۶۰ دقیقه) | `notifications-due` — `NotificationBotService::runDue()` + دفتر `plan_notifications_sent` | **`CronWiringTest`** |
| `cron/winback_cron.php` (هر ۳۰ دقیقه، سقف ۲۰۰) | `winback-queued` — `WinbackAdminService::processQueued($sender, 200)` | **`CronWiringTest`** |
| `cron/db_backup_cron.php` (هر ۱۵ دقیقه) | `db-backup-due` — `DbBackupAdminService::isDue()/runDue()` (بازیابی از اسلات جاافتاده) | **`CronWiringTest`** |
| فلش-سیل/اعتبار خوشآمد | بدون کرون — رویدادمحور (بنر در خرید، هدیه در `/start`) | تستهای خودشان |

نکته: دو سرویس `WinbackBotService`/`CardTrackBotService` به‌صورت زیرسرویس
استفاده میشوند — تارگِت بازگشت، همان `WinbackAdminService` کمپین است
(کلاینت‌های بیخرید طی `inactive_days`).

## Wave 5 — یکپارچهسازی

| مورد | جایگزین |
|---|---|
| ثبت هندلرها | `app/Telegram/HandlerRegistry.php` (ترتیب: payment → crypto → gateway → coupon → customplan → loyalty → wallet → … → support → ticket → reseller → faq → admin) |
| روت سایدبار + امنیت | `routes/web.php` (`admin.security.show`, `pay.gateway.callback`) |
| مستندات | همین فایل + `feature-port-checklist.md` |