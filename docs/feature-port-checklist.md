# چک‌لیست همگام‌سازی با Connectix - feature (Airoxnet)

مرجع: `C:\xampp\htdocs\Bots\Connectix - feature` (فورک مرجع UI/قابلیت).
مستند تغییرات: `AIROXNET_CHANGES.md` — اسکیمای دیتابیس: `setup/migrations.php` + `database.sql`.
قاعده: هر آیتم فقط بعد از **سبز شدن تست‌های خودش** تیک می‌خورد؛ هیچ آیتمی بدون تست قبول نیست.

---

## موج ۱ — زیرساخت مشترک (قبل از بقیه)

- [x] **W1.1** مایگریشن جدول‌های فروش/مالی: `resellers`, `reseller_requests`, `reseller_referrals`, `reseller_commissions`, `reseller_payouts`, `payment_cards`, `flash_sales`, `transactions_audit_log`
- [x] **W1.2** مایگریشن جدول‌های پشتیبانی/مدیریت: `tickets`, `ticket_messages`, `admins` + `is_primary`/`is_active`/`permissions`/`2fa`, `login_attempts`, `admin_activity`
- [x] **W1.3** مایگریشن جدول‌های ربات: `bot_buttons`, `bot_emojis`, `emoji_packs_imported`, `broadcast_logs`, `broadcast_recipients`, `scheduled_broadcasts`
- [x] **W1.4** مایگریشن جدول‌های درگاه/کریپتو: `payment_gateways`, `gateway_orders`, `crypto_payments`, `crypto_hashes`
- [x] **W1.5** مایگریشن جدول‌های فروشگاه/چرخه حیات: `loyalty_points`, `loyalty_rewards`, `loyalty_redemptions`, `loyalty_codes`, `panels`, `local_plans`, `custom_plan_settings`
- [x] **W1.6** مایگریشن جدول‌های اعلان/بازگشت/اعتبار: `notification_settings`, `plan_notifications_sent`, `winback_settings`, `winback_logs`, `winback_sent`, `welcome_credit_log`, `coupon_restrictions`
- [x] **W1.7** ستون‌های جدید روی جداول موجود: `payments.src_card`, `payments.receipt_image/transaction_id/approved_by/approved_at/delivered_service_info/currency/gateway_data`, `wallet_transactions.src_card/description`, `users.referred_by/is_blocked`, `clients.count_of_devices/is_deleted/expire_date/plan_status`

## موج ۲ — کروم پنل و اسکین‌ها (UI فقط)

- [x] **W2.1** ۸ تم ThemeService (airox-gold، airox-violet، اقیانوس، جنگل، غروب، رز، نیمه‌شب، گرافیت) در `connectix.css` + صفحه «ظاهر و تم» (admin/appearance) با پیش‌نمایش زنده و ذخیره تم پیش‌فرض پنل
- [x] **W2.2** منوی گروهی کامل Airoxnet در sidebar: گروه‌های عمومی/فروشگاه/ربات/حساب با badge تیکت باز، کارت نسخه، لینک کانال، فیلتر بر اساس نقش/دسترسی ادمین
- [x] **W2.3** topbar کامل: pill وضعیت سیستم، سلام روز/شب + ساعت تهران زنده، تاریخ شمسی، zنگارش swatch پوسته، دکمه تم
- [x] **W2.4** رفرش داشبورد به سبک Airoxnet: KPI های کامل (کاربران، مشتریان، درآمد امروز/ماه/کل، تیکت باز، نمایندگان فعال) + sparkline + نمودار وضعیت تراکنش‌ها (doughnut) + پراستفاده‌ترین کدهای تخفیف + بازه نمودار (۷/۳۰/۹۰ روز) — بدون شکستن قواعد v22-v24 (تاریخ شمسی، حذف کارت سرویس‌های فعال، «—» برای دوره صفر)
- [x] **W2.5** صفحه «ایمیل و رمز عبور» (حساب) + «امنیت حساب» + «فعالیت‌ها» (admin_activity)

## موج ۳ — صفحات پنل (هر صفحه: کنترلر + ویو + تست)

- [x] **W3.1** کاربران مستقل و سرویس‌ها (`localservices`) — افزودن کاربر با چت‌آیدی، ساخت سرویس روی پنل محلی، جستجو، بلاک/رفرش/حذف
- [x] **W3.2** نمایندگی‌ها (`reseller`) — لیست، درصد کمیسیون، سهمیه تست رایگان (ماهانه/کلی + صفر کردن مصرف)، درخواست‌ها، تنظیمات
- [x] **W3.3** تیکت‌ها (`tickets`) — لیست با badge باز، پاسخ ادمین، تغییر وضعیت، رتبه‌دهی، تاریخچه
- [x] **W3.4** ادمین‌ها (`admins`) — ساخت/ویرایش/فعال‌غیرفعال، دسترسی جداگانه هر ادمین، 2FA، قفل ادمین اصلی
- [x] **W3.5** درگاه‌های پرداخت (`gateways`) — زرین‌پال/نکست‌پی/NOWPayments + صف فعال‌سازی و نرخ
- [x] **W3.6** پنل‌های VPN و پلن‌ها (`panels`) — Marzban/3x-ui/Remnawave/Hiddify + پلن‌های محلی + تست اتصال + رمز AES-256-GCM
- [x] **W3.7** پرداخت کریپتو (`crypto`) — تنظیم شبکه/آدرس/تایید خودکار + صف بررسی هش‌ها
- [x] **W3.8** کد تخفیف (`coupons`) — کدهای محلی + محدودیت استفاده
- [x] **W3.9** پلن سفارشی (`custom-plan`) — قیمت/GB/روز + متن‌ها
- [x] **W3.10** حراج زمان‌دار (`flash-sales`) — تعریف/فعال‌سازی/حذف + بنر و تایمر ربات
- [x] **W3.11** سیستم امتیازدهی (`loyalty`) — تنظیم امتیاز، جوایز، لیست کدها، کسر/افزودن دستی با دلیل
- [x] **W3.12** کارت‌های مشکوک (`cards`) — آستانه + لیست کارت‌های تکراری
- [x] **W3.13** دکمه‌های صفحه اصلی (`buttons`) — ۳ صفحه (main_menu / buy_screen / os_screen)، برچسب، ایموجی، رنگ، ردیف/ستون
- [x] **W3.14** ایموجی‌های پرمیوم (`emojis`) — ثبت/ویرایش/پک/پیش‌نمایش
- [x] **W3.15** یادآور و هشدار (`notifications`) — روز قبل انقضا، درصد مصرف، تست ارسال
- [x] **W3.16** بازگشت مشتری (`winback`) — تنظیم روز نخرید، پیام، کد تخفیف، اجرا/توقف کمپین
- [x] **W3.17** ویژگی‌های ویژه (`features`) — برند، ایموجی استارت، اعتبار خوش‌آمدگویی، بکاپ دیتابیس خودکار، قفل نصب

## موج ۴ — سمت ربات (هندلرها + سرویس‌های سخت‌افزاری)

- [x] **W4.1** `CryptoPayService` — سفارش TRX/TON، نرخ نوبیتکس، استخراج هش، تایید خودکار، دکمه‌های ادمین؛ callback data `crypto_*`
- [x] **W4.2** `OnlineGatewayService` — زرین‌پال/نکست‌پی/NOWPayments، callback `gw_*`، callback URL
- [x] **W4.3** `CustomPlanService` — فروش GB/روز دلخواه، callback `customplan_*`
- [x] **W4.4** `TicketService` — تیکت در ربات، callback های `tickets*`/`myticket`/`reply`/`close`/`user`/`uclose`/`tkhist` + rateTicket
- [x] **W4.5** `ResellerService` — درخواست/تایید نمایندگی، کمیسیون، callback های `repreqok/no` + سهمیه تست
- [x] **W4.6** `LoyaltyService` — امتیاز از پرداخت، تبدیل به کد، اعمال در پرداخت، پیام ربات
- [x] **W4.7** دکمه‌های داینامیک + رنگ خودکار (`autoButtonColor`) در `KeyboardFactory` — خواندن `bot_buttons` + `default_button_color`
- [x] **W4.8** حراج زمان‌دار در خرید — قیمت خط‌خورده، تایمر، اعمال در همه روش‌های پرداخت
- [x] **W4.9** `FlashSaleService`/`CardTrackService`/`WelcomeCreditService`/`NotificationService`/`WinbackService` + کرانها — سیمکشی `routes/console.php` + `CronWiringTest` (8 تست)
- [x] **W4.10** شماره کارت پرداخت‌کننده در رسیدها (`payments.src_card`) + نمایش در لیست‌ها

## موج ۵ — همگرایی و تست نهایی

- [x] **W5.1** `HandlerRegistry` + مسیرهای وب همه صفحات جدید + منوی کناری با لینکهای فعال — ۲۴ مرجع سایدبار، صفر روت کمبودی (`admin.security.show` + `pay.gateway.callback` افزوده شد)
- [x] **W5.2** `docs/feature-port.md` — جدول نگاشت هر قابلیت Airoxnet به فایل Laravel (۳۸ ارجاع تست + ۹ مرجع فایل، همه راستیآزماییشده)
- [x] **W5.3** suite کامل سبز — **821 تست / 3375 assertion** + `pint --test` passed + `composer validate` valid + `git diff --check` OK
- [ ] **W5.4** commit های تفکیک‌شده per-wave + push + آپدیت همین چک‌لیست

---

### قرارداد‌های ثابت (همه موج‌ها)

- callback_data های legacy **دست نمی‌خورند** — همان رشته‌ها.
- `payments.is_paid` سه‌حالته (NULL = در انتظار)؛ قیمت VARCHAR با جداکننده هزارگان (پاک‌سازی با REPLACE قبل از CAST).
- درآمد داشبورد بر اساس `approved_at` (fallback `created_at` با `Schema::hasColumn`)، ماه‌شمسی تهران.
- قرمزی/سبزی دکمه‌ها: ✅/«تایید/پرداخت» → سبز، ❌/«انصراف/لغو/حذف» → قرمز (توابعی که همه کیبوردها را می‌پوشانند).
- هر ادمین فقط به بخش‌هایش دسترسی دارد؛ callback های ادمین با `AdminGuard`؛ هر کوئری اکانت ownership-check (anti-IDOR).
- CSP فقط self — هیچ CDN؛ Chart.js/Font Awesome از `public/vendor/`.
