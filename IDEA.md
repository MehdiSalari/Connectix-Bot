# مأموریت اصلی

تو باید پروژه فعلی `Connectix-Bot` را که یک ربات فروش و مدیریت سرویس VPN بر پایه PHP است، به یک معماری تمیز، قابل نگهداری و Production-ready با **Laravel 13** بازنویسی کنی.

Repository:

`MehdiSalari/Connectix-Bot`

مسیر پروژه روی سیستم:

`C:\xampp\htdocs\Bots\Connectix`

Branch فعلی:

`laravel-rewrite`

این پروژه قبلاً یک نسخه Legacy دارد که در حال حاضر رفتار واقعی و Business Logic اصلی سیستم در آن قرار دارد.

**هدف این کار بازنویسی کامل پروژه بدون از دست دادن هیچ Business Logic، قابلیت، پیام، وضعیت، Callback، پرداخت، کیف پول، اتصال به Connectix API و رفتار فعلی Bot است.**

---

# قانون بسیار مهم

این پروژه را از صفر طراحی نکن.

قبل از هر تغییر:

1. کل ساختار پروژه فعلی را بررسی کن.
2. کد Legacy را بخوان.
3. `functions.php`
4. `bot.php`
5. `index.php`
6. `config.example.php`
7. `setup/`
8. `update/`
9. `bank/`
10. `transactions/`
11. `users/`
12. `broadcast/`
13. `version.txt`
14. `setup/bot_config.json`
15. تمام فایل‌های مربوط به API، Payment، Wallet، Client، User، Plan و Telegram

را بررسی کن.

اگر به GitHub repository دسترسی داری، Repository اصلی را هم بررسی کن و صرفاً بر اساس حدس یا اطلاعات ناقص پیاده‌سازی نکن.

---

# هدف معماری

معماری نهایی باید Laravel باشد و از این مفاهیم استفاده کند:

* Laravel 13
* PHP 8.3+
* Eloquent ORM
* Migrations
* Models
* Controllers
* Form Requests در صورت نیاز
* Services
* Actions / Use Cases
* Middleware
* Blade برای Admin Panel
* Laravel Routing
* Laravel Logging
* Artisan Commands
* Scheduler در صورت امکان
* Telegram Webhook

از تبدیل `functions.php` به یک فایل بزرگ مثل:

`FunctionsService.php`

خودداری کن.

Business Logic باید بر اساس Domain تقسیم شود.

---

# ساختار پیشنهادی

ساختار نهایی باید تقریباً به شکل زیر باشد، ولی در صورت نیاز بر اساس بررسی واقعی پروژه آن را اصلاح کن:

```text
app/
├── Console/
│   └── Commands/
│
├── Http/
│   ├── Controllers/
│   │   ├── Telegram/
│   │   ├── Admin/
│   │   ├── Payment/
│   │   └── ...
│   │
│   ├── Middleware/
│   └── Requests/
│
├── Models/
│   ├── User.php
│   ├── Client.php
│   ├── Payment.php
│   ├── Wallet.php
│   ├── WalletTransaction.php
│   ├── SmsPayment.php
│   └── Admin.php
│
├── Services/
│   ├── Telegram/
│   │   └── TelegramService.php
│   │
│   ├── Connectix/
│   │   └── ConnectixService.php
│   │
│   ├── Payment/
│   │   ├── PaymentService.php
│   │   └── SmsPaymentService.php
│   │
│   ├── Wallet/
│   │   └── WalletService.php
│   │
│   ├── Coupon/
│   │   └── CouponService.php
│   │
│   ├── Plan/
│   │   └── PlanService.php
│   │
│   ├── User/
│   │   └── UserService.php
│   │
│   └── ...
│
└── Actions/
    ├── Purchase/
    ├── Renewal/
    ├── Client/
    ├── Payment/
    └── ...
```

این ساختار اجباری نیست؛ اگر هنگام بررسی پروژه ساختار بهتری پیدا کردی، استفاده کن.

اما اصل مهم این است:

**Domainها از هم جدا باشند و Business Logic در Controllerها پخش نشود.**

---

# قابلیت‌های اصلی که باید حفظ شوند

تمام قابلیت‌های موجود Legacy باید در Laravel حفظ شوند.

هیچ Feature را فقط به دلیل اینکه قدیمی است حذف نکن.

## 1. Telegram Bot

ربات فعلی:

`EchoSafeBot`

Bot باید در Laravel از طریق Webhook کار کند.

Legacy:

```text
bot.php
```

باید تبدیل شود به چیزی در این ساختار:

```text
TelegramWebhookController
        ↓
Telegram Update Dispatcher
        ↓
Handlers / Actions / Services
```

Webhook باید بتواند Updateهای Telegram را دریافت کند.

حداقل این موارد را پوشش بده:

* `/start`
* Message
* Callback Query
* Contact
* Photo
* Document
* Receipt / Payment related messages
* Inline keyboard callbacks
* User interactions
* Admin interactions

کد Telegram API باید در یک abstraction مثل:

```php
TelegramService
```

قرار بگیرد.

نباید در تمام Controllerها و Serviceها مستقیم cURL تکرار شود.

---

# 2. Telegram User

Legacy جدول:

```text
users
```

Model:

```php
User
```

فیلدهای اصلی فعلی:

```text
id
chat_id
telegram_id
name
email
phone
avatar
action
test
created_at
```

Relationshipها را با Eloquent تعریف کن.

مثلاً:

```text
User
 ├── clients
 └── wallet/payment relations where applicable
```

اطلاعات User باید هنگام دریافت Update از Telegram به شکل صحیح create/update شود.

---

# 3. User State / actionStep

در Legacy:

```text
users.action
```

برای نگهداری state کاربر استفاده می‌شود.

این قسمت بسیار مهم است.

کاربر در فرآیند خرید ممکن است در مرحله‌های مختلف باشد:

```text
new
renew
group
devices
plan
payment
receipt
...
```

این State Machine باید در Laravel حفظ شود.

اما به جای پراکنده کردن JSON manipulation در کل پروژه، یک abstraction مشخص بساز.

مثلاً:

```php
UserStateService
```

یا ساختار مشابه.

باید بتوانیم:

```php
getState()
setState()
updateState()
clearState()
```

را به شکل تمیز انجام دهیم.

Behavior فعلی نباید تغییر کند.

---

# 4. Purchase Flow

منطق اصلی خرید را دقیقاً از Legacy استخراج کن.

Flow فعلی به صورت کلی:

```text
New Purchase
    ↓
Plan Group
    ↓
Number of Devices
    ↓
Plan
    ↓
Checkout
    ↓
Payment Method
    ↓
Payment
    ↓
Create Client
```

باید حفظ شود.

هر مرحله باید مسئولیت مشخص داشته باشد.

مثلاً:

```text
PurchaseAction
CheckoutAction
CreateClientAction
PaymentAction
```

یا ساختار مشابه.

---

# 5. Renewal

منطق:

```php
renew($info)
```

در Legacy را دقیق بررسی کن و به Laravel منتقل کن.

Renew باید بتواند:

* Client موجود را پیدا کند
* Plan جدید را انتخاب کند
* Payment را انجام دهد
* Connectix API را صدا بزند
* Client را Update کند
* وضعیت Payment را ثبت کند
* نتیجه را به Telegram اعلام کند

هیچ بخشی از رفتار فعلی را حدس نزن.

---

# 6. Connectix API

یکی از مهم‌ترین بخش‌های پروژه است.

Legacy:

```text
getSellerPlans()
createClient()
updateClient()
getClientData()
getClientByUsername()
...
```

همه این منطق‌ها باید در:

```php
ConnectixService
```

یا چند Service مرتبط قرار بگیرند.

مثلاً:

```php
ConnectixService
```

مسئول:

* API Authentication
* Request
* Response parsing
* Error handling
* Plans
* Clients
* Create client
* Update client
* Client details

باشد.

از HTTP Client خود Laravel استفاده کن.

مثلاً:

```php
Http::...
```

به جای تکرار cURL.

Tokenها و Credentials نباید داخل Git قرار بگیرند.

آنها باید از `.env` / config خوانده شوند.

---

# 7. Client

Legacy جدول:

```text
clients
```

Model:

```php
Client
```

فیلدهای فعلی:

```text
id
count_of_devices
username
password
chat_id
user_id
created_at
```

Relationship:

```text
Client belongsTo User
```

Client creation و update باید از طریق Connectix Service انجام شود.

---

# 8. Plan System

Plan parser یکی از قسمت‌های مهم پروژه است.

Legacy:

```php
parsePlanTitle()
approximateDays()
parseType()
parseTypeWithEmoji()
getSellerPlans()
getSellerPlanGroupName()
planMatchesGroup()
getAvailableDeviceCountsFromPlans()
```

این منطق نباید از بین برود.

باید یک:

```php
PlanService
```

ایجاد شود.

Parser باید تمام syntaxهای فعلی Planها را پشتیبانی کند.

از جمله مواردی مثل:

```text
device count
Free
traffic
GB
Unlimited
D
W
M
Y
gift days
BCSublink
Sublink
Economic
Static IP
Iran Access
Business Class
```

و هر syntax دیگری که در کد Legacy پیدا می‌کنی.

**قبل از پیاده‌سازی PlanService تمام Plan parsing موجود را از Legacy استخراج کن.**

---

# 9. Payment

Payment system را دقیق بررسی و منتقل کن.

Legacy:

```php
payment()
savePayment()
paycheck()
checkout()
```

Payment methods شامل موارد فعلی پروژه است، از جمله:

```text
Card-to-card
Wallet
Bank SMS matching
```

و هر روش دیگری که در Legacy وجود دارد.

Model:

```php
Payment
```

جدول فعلی:

```text
payments
```

فیلدها:

```text
id
order_number
chat_id
client_id
plan_id
price
coupon
is_paid
method
created_at
```

در مرحله اول **schema را بدون تغییر رفتاری حفظ کن.**

یعنی اگر Legacy قیمت را VARCHAR ذخیره می‌کند، در migration اولیه آن را بدون تغییر ناگهانی به DECIMAL تبدیل نکن.

اول Parity.

بعداً می‌توانیم Migration اصلاحی داشته باشیم.

---

# 10. Card-to-Card Payment

Flow فعلی را حفظ کن:

```text
User selects card payment
        ↓
Order created
        ↓
Payment instructions
        ↓
Receipt sent
        ↓
Admin reviews
        ↓
Approve / Reject
        ↓
If approved:
    Create / Renew Client
```

تمام Callbackها و پیام‌های فعلی باید حفظ شوند.

---

# 11. Wallet

Legacy دارای:

```text
wallets
wallet_transactions
```

است.

Models:

```php
Wallet
WalletTransaction
```

Service:

```php
WalletService
```

باید عملیات‌هایی مثل:

```text
create wallet
get balance
increase balance
decrease balance
create transaction
get transactions
check transaction
```

را پوشش دهد.

عملیات مالی باید تا حد امکان Transaction-safe باشد.

برای عملیات حساس از:

```php
DB::transaction()
```

استفاده کن.

---

# 12. Coupon

Legacy:

```php
checkCoupon()
discount()
```

Coupon logic را کامل بررسی کن.

مواردی مثل:

* فعال بودن Coupon
* تاریخ شروع
* تاریخ پایان
* Plan compatibility
* Discount
* محدودیت‌ها

باید حفظ شوند.

اگر Coupon در Legacy جدول خاصی ندارد، فعلاً بر اساس همان منطق موجود پیاده‌سازی کن و schema جدید بدون نیاز ایجاد نکن.

---

# 13. Telegram Channel Membership

Legacy:

```php
checkUserChannelJoin()
```

این قابلیت باید حفظ شود.

Config فعلی:

```text
telegram_channel_id
force_channel_join
channel_telegram
```

اگر:

```text
force_channel_join = true
```

باشد، رفتار فعلی بررسی عضویت باید حفظ شود.

Telegram API call مربوط به:

```text
getChatMember
```

باید از TelegramService عبور کند.

---

# 14. Test Account

Legacy:

```php
getTest()
```

و User:

```text
test
```

را بررسی کن.

منطق Free/Test Account را دقیقاً منتقل کن.

شامل:

* شرایط دریافت
* محدودیت
* Plan
* Client creation
* Telegram message
* وضعیت test

---

# 15. Guides

Legacy:

```php
getCustomGuideItems()
getGuideLink()
guideButton()
guide()
```

این بخش را نیز منتقل کن.

Guide باید بتواند بر اساس platform / action لینک یا محتوای درست را برگرداند.

---

# 16. Download Links

Legacy:

```php
getDownloadLinks()
```

این قسمت باید بررسی شود.

اگر پروژه از سایت خارجی مثل:

```text
connectix.space
```

لینک‌ها را استخراج می‌کند، همان behavior باید حفظ شود.

اما:

* Timeout داشته باشد
* Error handling داشته باشد
* Logging داشته باشد
* در صورت امکان Cache داشته باشد
* scraping logic در Controller نباشد

---

# 17. Bot Configuration

فایل فعلی:

```text
setup/bot_config.json
```

دارای تنظیمات مهم است.

مانند:

```text
app_name
admin_id
admin_id_2
admin_id_3
support_telegram
channel_telegram
telegram_channel_id
card_number
card_name
bank
test
bot_active
force_channel_join
plan_group_names
messages
```

این تنظیمات را بررسی و دسته‌بندی کن.

Secretها نباید داخل Repository باشند.

ساختار پیشنهادی:

```text
.env
config/echovpn.php
```

مثلاً:

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=
CONNECTIX_PANEL_TOKEN=
DB_...
```

و:

```php
config('echovpn...')
```

برای تنظیمات غیر-secret.

پیام‌های Bot را فعلاً لازم نیست حتماً به Database منتقل کنی.

اگر انتقال به:

```text
config/echovpn.php
```

منطقی‌تر است، انجام بده.

بعداً می‌توان Admin Settings را اضافه کرد.

---

# مهم: Setup / First-Run Installation System

این پروژه فقط یک Bot آماده نیست.

Legacy دارای یک **Setup / Installation Flow** است و این Flow بخشی از معماری واقعی Application محسوب می‌شود.

فایل‌های مهم Legacy:

```text
setup/
├── index.php
├── setup.php
└── setup_progress.php
```

و فایل مرتبط:

```text
setup/bot_config.json
```

قبل از حذف یا جایگزینی این بخش، تمام فایل‌های Setup را کامل بررسی کن.

---

## مفهوم Setup

Application باید بتواند تشخیص دهد که آیا قبلاً نصب و پیکربندی شده است یا خیر.

ممکن است Application کاملاً تازه باشد و:

* Database هنوز آماده نباشد یا Schema لازم ایجاد نشده باشد
* Bot Telegram متصل نشده باشد
* Bot Token تنظیم نشده باشد
* Connectix Panel/API متصل نشده باشد
* Connectix credentials تنظیم نشده باشند
* Admin Account ایجاد نشده باشد
* Bot Configuration وجود نداشته باشد
* Webhook تنظیم نشده باشد
* Configurationهای اولیه کامل نشده باشند
* Setup هنوز Complete نشده باشد

در چنین شرایطی Application نباید مستقیماً وارد Normal Bot/Application Flow شود.

باید وارد:

```text
Setup / Installation Wizard
```

شود.

---

# Setup State

یک مکانیزم مشخص برای تشخیص وضعیت Installation ایجاد کن.

مثلاً:

```text
not_installed
installing
configured
failed
```

یا ساختار مناسب‌تری که از بررسی Legacy به دست می‌آید.

**نام دقیق مهم نیست؛ رفتار مهم است.**

Application باید بتواند مشخص کند:

```text
Is the application installed?
Is the database initialized?
Is the bot configured?
Is the Connectix integration configured?
Is an admin account available?
Is the setup complete?
```

---

# First Run Detection

در هر Request/Entry Point که لازم است، Application باید بتواند تشخیص دهد که Setup کامل نشده است.

مثلاً:

```text
Application
    ↓
Installation Check
    ↓
Setup Complete?
    ├── NO  → Setup Wizard
    └── YES → Normal Application
```

این Check نباید به شکل پراکنده در کل پروژه تکرار شود.

از یک abstraction مشخص استفاده کن، مثلاً:

```php
InstallationService
```

یا:

```php
SetupService
```

و در صورت نیاز:

```php
EnsureInstalled
```

Middleware.

---

# Setup Wizard

Setup باید به شکل مرحله‌ای باشد.

حداقل Flow را بر اساس Legacy استخراج کن و حفظ کن.

ساختار مفهومی:

```text
Step 1
Application / Environment Check
        ↓
Step 2
Database Setup
        ↓
Step 3
Connectix Configuration
        ↓
Step 4
Telegram Bot Configuration
        ↓
Step 5
Admin Account
        ↓
Step 6
Bot Configuration
        ↓
Step 7
Webhook / Integration Validation
        ↓
Step 8
Final Validation
        ↓
Setup Complete
```

**این ترتیب فقط نمونه است.**

ترتیب واقعی را از:

```text
setup/index.php
setup/setup.php
setup/setup_progress.php
```

استخراج کن و اگر Legacy ترتیب متفاوتی دارد، همان Business/Installation Logic را حفظ کن.

---

# Database Setup

Setup باید وضعیت Database را بررسی کند.

مواردی مانند:

```text
Database connection
Database existence/access
Required tables
Schema
Migrations
```

را بررسی کن.

اگر پروژه تازه است، Setup باید بتواند Database/Application schema را آماده کند.

اگر Database موجود است:

**هرگز بدون بررسی و تأیید وضعیت، اطلاعات موجود را حذف نکن.**

مخصوصاً از اجرای خودکار:

```bash
migrate:fresh
db:wipe
TRUNCATE
DROP
```

روی Database واقعی خودداری کن.

Setup باید بتواند بین:

```text
Fresh Installation
```

و:

```text
Existing/Legacy Installation
```

تفاوت بگذارد.

---

# Connectix Setup

یکی از مراحل مهم Setup اتصال به Connectix است.

Setup باید بتواند:

```text
Connectix Panel/API URL
Credentials / Token
Connection Test
API Authentication Test
```

را بررسی کند.

اگر اتصال موفق بود:

```text
Connectix: Connected
```

و اگر شکست خورد:

```text
Connectix: Not Connected
```

با Error قابل فهم نمایش داده شود.

Credentials نباید در Source Code یا Repository ذخیره شوند.

از:

```text
.env
```

و:

```text
config/...
```

استفاده کن.

---

# Telegram Bot Setup

Setup باید بتواند Telegram Bot را configure و validate کند.

موارد مهم:

```text
Bot Token
Bot identity
Bot username
Telegram API connectivity
Webhook URL
Webhook Secret
```

را بررسی کن.

در صورت امکان Setup باید با Telegram API صحت Token را تست کند.

مثلاً با:

```text
getMe
```

یا API مناسب دیگر.

اگر Token نامعتبر است:

```text
Setup must not be marked as complete.
```

---

# Telegram Webhook Setup

بعد از معتبر بودن Bot Token، Setup باید وضعیت Webhook را بررسی کند.

در صورت نیاز Webhook را تنظیم کند یا حداقل امکان تنظیم/Validation آن را فراهم کند.

باید مشخص باشد:

```text
Webhook URL
Webhook Secret
Webhook Status
```

و در پایان بتوانیم بگوییم:

```text
Telegram Bot: Connected
Webhook: Configured
```

یا خطای دقیق نمایش داده شود.

---

# Admin Account Setup

Application بدون Admin اولیه نباید Setup شده محسوب شود.

Setup باید بتواند اولین Admin را ایجاد کند.

حداقل:

```text
Admin name/email
Password
Telegram Chat/User ID
Role
```

را بر اساس رفتار واقعی Legacy مدیریت کند.

Password باید با Laravel Hash ذخیره شود.

بعد از ایجاد اولین Admin:

```text
Admin Account: Configured
```

شود.

اگر Legacy دارای چند Admin ID یا Admin configuration است، آن منطق را نیز بررسی و حفظ کن.

---

# Bot Configuration Setup

تنظیمات موجود در:

```text
setup/bot_config.json
```

را کامل بررسی کن.

از جمله مواردی مانند:

```text
app_name
admin_id
admin_id_2
admin_id_3
support_telegram
channel_telegram
telegram_channel_id
card_number
card_name
bank
test
bot_active
force_channel_join
plan_group_names
messages
```

هر مقدار را دسته‌بندی کن:

```text
Secret
Environment Configuration
Application Configuration
Bot Configuration
Content
```

Secretها:

```text
.env
```

Application configuration:

```text
config/...
```

و اگر بعضی تنظیمات واقعاً باید توسط Admin قابل تغییر باشند، در مرحله مناسب می‌توان آنها را به Database منتقل کرد.

اما در اولین Rewrite، **صرفاً برای زیبایی معماری Business Logic را تغییر نده.**

---

# Setup Validation

قبل از اینکه Setup را Complete اعلام کنی، یک Validation نهایی اجرا کن.

حداقل:

```text
✓ Database
✓ Required tables
✓ Laravel application
✓ Telegram Bot Token
✓ Telegram API
✓ Connectix API
✓ Connectix credentials
✓ Admin account
✓ Bot configuration
✓ Webhook
✓ Required directories / permissions
```

را بررسی کن.

نتیجه باید چیزی شبیه:

```text
Installation Check

[✓] Database
[✓] Database Schema
[✓] Telegram Bot
[✓] Telegram API
[✓] Telegram Webhook
[✓] Connectix API
[✓] Admin Account
[✓] Bot Configuration

Installation completed successfully.
```

باشد.

اگر هر مورد Critical شکست خورد:

```text
Setup Complete = false
```

باقی بماند.

---

# Setup Completion

پس از موفقیت کامل Setup، یک وضعیت قابل اعتماد برای Application ذخیره کن.

مثلاً:

```text
installed = true
```

یا ساختار مناسب‌تر.

اما این وضعیت نباید تنها معیار باشد.

در صورت نیاز، Application باید بتواند Configuration را دوباره Validate کند.

---

# Re-run Setup

Setup نباید فقط یک صفحه‌ای باشد که بعد از نصب برای همیشه غیرقابل دسترسی شود.

باید بتوانیم در صورت نیاز Setup/Configuration را دوباره بررسی کنیم.

مثلاً:

```text
/admin/settings
```

یا Setup Maintenance Flow مناسب.

اما:

**Setup مجدد نباید بدون هشدار باعث حذف Database، Admin یا Configuration شود.**

---

# Setup Protection

Setup باید از نظر امنیتی محافظت شود.

به‌خصوص:

* Setup نباید بعد از Installation کامل برای کاربران عادی قابل دسترسی باشد.
* Setup نباید اجازه ایجاد Admin جدید بدون Authorization بدهد.
* Credentials نباید در HTML، Log یا Exception نمایش داده شوند.
* Setup endpointهای حساس نباید Public باقی بمانند.
* اگر Application هنوز نصب نشده، فقط Setup Flow باید در دسترس باشد.
* بعد از Complete شدن Setup، مسیرهای Installation باید Block یا محدود شوند.

---

# Important: Setup is Part of Application Lifecycle

این اصل بسیار مهم است:

```text
Fresh Application
        ↓
Setup
        ↓
Configured Application
        ↓
Normal Runtime
```

و نه:

```text
Fresh Application
        ↓
Normal Bot
        ↓
Hope configuration exists
```

اگر Application تازه نصب شده باشد، باید خودش این وضعیت را تشخیص دهد و کاربر را به Setup هدایت کند.

این رفتار باید در Laravel Rewrite حفظ شود.

---

# Legacy Setup Parity

قبل از Implementation:

```text
setup/index.php
setup/setup.php
setup/setup_progress.php
setup/bot_config.json
```

را کامل بخوان.

تمام کارهایی که Setup فعلی انجام می‌دهد استخراج کن و برای هرکدام مشخص کن:

```text
Legacy Setup Behavior
        ↓
Laravel Setup Equivalent
```

هیچ مرحله‌ای را صرفاً به این دلیل که «در Laravel بهتر است جور دیگری انجام شود» حذف نکن.

اگر Laravel architecture بهتری وجود دارد، Architecture را تغییر بده ولی **نتیجه و قابلیت Setup را حفظ کن.**

---

# Setup Tests

برای Setup تست بنویس.

حداقل سناریوها:

```text
Fresh installation
Missing configuration
Missing Telegram token
Invalid Telegram token
Missing Connectix credentials
Invalid Connectix credentials
Missing admin
Existing database
Existing legacy database
Setup already completed
Incomplete setup
Failed setup step
Setup retry
Setup access after installation
```

Setup نباید باعث از بین رفتن داده‌های موجود شود.

---

# Setup Definition of Done

Setup زمانی کامل محسوب می‌شود که:

* [x] Fresh Installation تشخیص داده شود. (`InstallationService` وضعیت را از محیط، دیتابیس، schema، credentialها و جدول ادمین زنده می‌خواند؛ `storage/app/connectix/setup.json` فقط marker پیشرفت است و معیار نصب بودن نیست.)
* [x] Unconfigured Application به Setup هدایت شود. (`EnsureInstalled` به‌صورت global prepend شده و endpointهای machine پاسخ JSON با status 503 می‌دهند.)
* [x] Setup State مشخص و قابل اعتماد باشد. (`SetupStateStore` + `SetupState`، `SetupWizard::next()` با `SetupStep::isDone()` و reset بدون حذف داده.)
* [x] Database Setup بررسی/پیاده‌سازی شده باشد. (گام `database`: نوشتن `DB_*` با `EnvWriter`، `CREATE DATABASE IF NOT EXISTS` اختیاری و تست اتصال روی connection فعال.)
* [x] Required Schema بررسی/ایجاد شده باشد. (گام `migrations` فقط `migrate --force` اجرا می‌کند و جدول‌های ایجادنشده را گزارش می‌دهد؛ هیچ `migrate:fresh`/`db:wipe`/`truncate` در installer نیست.)
* [x] Telegram Configuration انجام شود. (توکن ربات در گام `telegram` فقط در `.env` نوشته می‌شود و هرگز به HTML یا state برنمی‌گردد.)
* [x] Telegram Token Validation انجام شود. (`getMe()`؛ توکن نامعتبر خطای گام است، نه warning.)
* [x] Telegram API Connection تست شود. (همان probe، همراه با گزارش خطای قابل فهم.)
* [x] Webhook Configuration انجام شود. (`setWebhook` با `TELEGRAM_WEBHOOK_SECRET`؛ secret جداگانه و قابل تنظیم است.)
* [x] Webhook Validation انجام شود. (`getWebhookInfo`؛ mismatch بین URL ثبت‌شده و تنظیم محلی خطای گام است.)
* [x] Connectix Configuration انجام شود. (ایمیل، رمز و توکن پنل در گام `connectix`.)
* [x] Connectix Connection Test انجام شود. (`ConnectixService::login()` + پروب توکن فروشنده.)
* [x] Initial Admin Account ایجاد شود. (upsert روی `admins` با نقش `admin` و `admin_ids`.)
* [x] Bot Configuration تنظیم شود. (`PanelSettingsService::refresh()` + `CONNECTIX_BOT_*` در `.env`، جایگزین `bot_config.json`.)
* [x] Final Installation Validation انجام شود. (`summary()` همه چک‌ها را دوباره اجرا می‌کند و تا پاس شدن همه، `complete` وضعیت installed ثبت نمی‌کند.)
* [x] Setup Completion State ذخیره شود. (`markCompleted()` در state store و فعال شدن رفتار عادی runtime از همان لحظه.)
* [x] بعد از Setup موفق، Normal Application Flow فعال شود. (بدون ری‌استارت؛ `EnsureInstalled` وضعیت زنده را می‌بیند.)
* [x] بعد از Setup موفق، Setup عمومی مسدود شود. (`ProtectSetup`: فقط ادمین sign-in‌شده با نقش `admin`؛ بقیه 404، و تنها استثنا گزارش پایانی همان session نصب.)
* [x] Setup مجدد بدون عملیات مخرب امکان‌پذیر باشد. (`connectix:setup reset` فقط state file را پاک می‌کند؛ هیچ داده‌ای حذف نمی‌شود.)
* [x] Setup Security بررسی شود. (CSRF، throttle روی POSTها، 404 برای کاربر عادی، عدم نمایش/لاگ secret با `redact()`، و حل `APP_KEY` پیش از HTTP kernel با `ApplicationKey`.)
* [x] Fresh Installation تست شود. (`SetupTest` روی دیتابیس خالی با state file و env موقت.)
* [x] Existing/Legacy Database تست شود. (schema موجود بدون افت داده، و گام `import` با `legacy:import`/`legacy:verify`.)
* [x] Failure/Retry سناریوها تست شوند. (توکن/رمز نامعتبر، mismatch webhook، نبود legacy connection، گام ناتمام و retry، reset و بازگشت به گام اول.)


---

# 18. Admin IDs

Adminهای فعلی:

```text
admin_id
admin_id_2
admin_id_3
```

را در منطق Admin در نظر بگیر.

Admin authorization نباید در کل پروژه به شکل:

```php
if ($chatId == ...)
```

تکرار شود.

یک abstraction مناسب ایجاد کن.

---

# 19. Admin Panel

Legacy دارای صفحات:

```text
index.php
login.php
logout.php

users/
users/index.php
users/profile.php
users/search.php
users/user.php

transactions/
transactions.php
wallet_transactions.php
sms_payments.php
```

است.

این بخش باید به Laravel تبدیل شود.

مثلاً:

```text
AdminController
AdminAuthController

AdminUserController
AdminTransactionController
AdminWalletController
AdminSmsPaymentController
AdminClientController
```

و Blade views:

```text
resources/views/admin/
```

استفاده شود.

---

# 20. Admin Authentication

Legacy login:

```text
email
password
token
```

و اطلاعات Admin در جدول:

```text
admins
```

است.

Model:

```php
Admin
```

بساز.

Password hashing باید امن باشد.

از Laravel authentication/session استفاده کن.

Tokenها و secretها را plaintext در code قرار نده.

اگر ساختار authentication فعلی وابستگی خاصی به Connectix Seller API دارد، آن dependency را نیز حفظ کن.

---

# 21. Database

Schema فعلی را بررسی کرده‌ایم.

جداول اصلی:

```text
admins
users
clients
payments
wallets
wallet_transactions
sms_payments
```

برای آنها Migration و Model ایجاد کن.

Relationships:

```text
User
 └── hasMany Client

Client
 └── belongsTo User

Wallet
 └── hasMany WalletTransaction

WalletTransaction
 └── belongsTo Wallet
```

و relationshipهای منطقی Payment و SmsPayment را نیز در صورت امکان تعریف کن.

**در migration اولیه compatibility با دیتابیس Legacy مهم‌تر از زیباسازی schema است.**

اگر Production database موجود است، migration نباید باعث نابودی اطلاعات شود.

---

# 22. Legacy Database Compatibility

باید بتوانیم Database فعلی را به Laravel متصل کنیم.

فرض نکن که لازم است دیتابیس جدید خالی ساخته شود.

اول امکان Migration/Compatibility با Database فعلی را بررسی کن.

هدف:

```text
Existing MySQL
        ↓
Laravel Eloquent
        ↓
same users/payments/clients/wallets
```

اگر لازم بود Migrationهایی برای تطبیق schema بنویس.

اما:

**داده‌های موجود نباید حذف یا reset شوند.**

هیچ‌وقت بدون درخواست صریح:

```php
migrate:fresh
db:wipe
truncate
```

روی دیتابیس واقعی اجرا نکن.

---

# 23. Bank SMS

فایل Legacy:

```text
bank/sms.php
transactions/sms_payments.php
```

را کامل بررسی کن.

این بخش مربوط به دریافت SMS بانکی و تطبیق مبلغ با Paymentهای pending است.

منطق فعلی باید حفظ شود.

موارد مهم:

```text
message parsing
amount extraction
bank detection
payment matching
expiration
status
```

را منتقل کن.

Service پیشنهادی:

```php
SmsPaymentService
```

---

# 24. Transaction Matching

Legacy از SMS بانکی برای پیدا کردن Payment استفاده می‌کند.

این flow را خراب نکن.

مثلاً:

```text
Bank SMS
   ↓
Parse amount
   ↓
Find pending payment
   ↓
Match
   ↓
Mark paid
   ↓
Process payment
   ↓
Create/Renew client
```

تمام edge caseهای Legacy را بررسی و حفظ کن.

---

# 25. Broadcast

Legacy:

```text
broadcast/broadcast_start.php
broadcast/broadcast_progress.php
```

را بررسی کن.

Broadcast باید بتواند برای کاربران Telegram پیام ارسال کند.

به دلیل shared hosting:

* Worker دائمی طراحی نکن
* Redis اجباری نکن
* Horizon استفاده نکن

اگر نیاز به batching است، از روش مناسب shared hosting استفاده کن.

می‌توانی از:

```text
Artisan Command
```

یا Scheduler/Cron استفاده کنی.

---

# 26. Sync / Update

Legacy:

```text
update/bot.php
update/clients.php
update/clients_update.php
update/users.php
```

را بررسی کن.

اینها را در Laravel به:

```text
Artisan Commands
```

تبدیل کن.

مثلاً:

```bash
php artisan connectix:sync-clients
php artisan connectix:sync-users
php artisan connectix:sync-bot
```

نام دقیق مهم نیست؛ ساختار منطقی مهم است.

اگر shared hosting اجازه Cron بدهد، Scheduler قابل استفاده است.

---

# 27. Error Logging

Legacy:

```php
errorLog()
```

دارد.

در Laravel از:

```php
Log::...
```

استفاده کن.

Errorهای مهم:

* Telegram API
* Connectix API
* Payment
* Database
* Bank SMS
* Client creation
* Client renewal

باید Log شوند.

اما:

**Token، password، card secret و credential را داخل log ننویس.**

---

# 28. Telegram Error Handling

اگر Telegram API fail شد:

* Exception مناسب
* Log
* پاسخ مناسب
* عدم crash کل Webhook

باید وجود داشته باشد.

Webhook نباید با یک خطای داخلی تمام Bot را از کار بیندازد.

---

# 29. API / HTTP Layer

برای APIهای خارجی:

Laravel HTTP Client استفاده کن.

مثلاً:

```php
Http::timeout(...)
    ->retry(...)
```

در صورت مناسب بودن.

برای هر API:

* timeout
* retry در موارد مناسب
* response validation
* error handling

در نظر بگیر.

---

# 30. Security

در بازنویسی موارد امنیتی Legacy را نیز اصلاح کن، ولی بدون تغییر Business Logic.

مخصوصاً:

* Secretها در `.env`
* `.env` داخل Git نباشد
* Bot Token در source نباشد
* Connectix Token در source نباشد
* Webhook secret
* Session security
* CSRF برای Admin forms
* Input validation
* Authorization
* SQL Injection prevention
* XSS prevention
* Mass assignment protection
* Secure HTTP requests
* جلوگیری از log کردن secrets

اگر Legacy جایی SSL verification را خاموش کرده، آن را بدون دلیل حفظ نکن.

---

# 31. Shared Hosting Constraint

این پروژه قرار است روی Shared Hosting / cPanel نیز قابل Deploy باشد.

بنابراین:

نباید معماری وابسته به موارد زیر باشد:

```text
Docker
Redis
Horizon
WebSocket server
Supervisor
Long-running worker
Node server
```

مگر اینکه واقعاً ضروری باشد و دلیل فنی مشخص داشته باشد.

ترجیح:

```text
Laravel
MySQL
PHP
Composer
Cron
Telegram Webhook
```

باشد.

---

# 32. Telegram Webhook

برای Production، Telegram Bot باید Webhook داشته باشد.

مثلاً:

```text
POST /telegram/webhook
```

یا ساختار مشابه.

Webhook Secret را بررسی کن.

Route باید protected باشد.

Bot نباید برای Production به polling دائمی وابسته باشد.

---

# 33. Admin Routes

Routeها را تمیز و قابل فهم طراحی کن.

مثلاً:

```text
/admin/login
/admin/logout

/admin
/admin/users
/admin/users/{user}

/admin/clients
/admin/clients/{client}

/admin/payments
/admin/payments/{payment}

/admin/wallets
/admin/wallet-transactions

/admin/sms-payments
```

Route names استاندارد استفاده کن.

---

# 34. Blade

Admin Panel را با Blade بساز.

Controllerها نباید HTML بزرگ تولید کنند.

HTML باید در:

```text
resources/views/admin/
```

باشد.

Layout مشترک بساز.

---

# 35. Existing UI

اگر Legacy Admin Panel UI قابل استفاده است، آن را تا حد امکان حفظ کن.

اولویت:

```text
Behavior parity
+
Data parity
+
Feature parity
```

بعد:

```text
UI improvement
```

است.

بازنویسی نباید باعث شود Admin Panel فعلی از نظر functionality ناقص شود.

---

# 36. version.txt

فایل:

```text
version.txt
```

در پروژه باقی بماند.

فعلاً حذفش نکن.

Version داخلی Application را از Git Tags جدا در نظر بگیر.

تا زمانی که Rewrite کامل و Production-ready نشده، version را بدون دلیل تغییر نده.

---

# 37. Git

نسخه Legacy فعلی:

```text
v3.3.6
```

است.

Branch:

```text
laravel-rewrite
```

است.

در طول کار commitهای منطقی ایجاد کن.

مثلاً:

```text
feat: add Laravel database models
feat: add Telegram service
feat: add Connectix service
feat: migrate purchase flow
feat: migrate payment flow
feat: migrate wallet
feat: migrate admin panel
```

اما commit messageها باید بر اساس کار واقعی باشند.

---

# 38. Legacy Code

در مراحل اولیه:

**Legacy را حذف نکن.**

فایل‌هایی مثل:

```text
bot.php
functions.php
index.php
login.php
users/
transactions/
bank/
update/
broadcast/
```

تا زمانی که Laravel جایگزین آنها نشده، دست‌نخورده باقی بمانند.

هدف:

```text
Legacy
   ↓
Laravel replacement
   ↓
Parity verification
   ↓
Legacy removal
```

است.

---

# 39. Migration Strategy

پیاده‌سازی را یکباره و کور انجام نده.

این ترتیب را دنبال کن:

## Phase 1 — Audit

کل Legacy را بررسی کن.

خروجی داخلی خودت باید شامل:

```text
Legacy file
↓
Responsibility
↓
Laravel destination
```

باشد.

مثلاً:

```text
functions.php
    ↓
TelegramService
WalletService
PaymentService
PlanService
ConnectixService
UserService
...

bot.php
    ↓
TelegramWebhookController
TelegramUpdateDispatcher
...
```

---

# Phase 2 — Laravel Foundation

بررسی و تکمیل:

```text
composer.json
.env.example
config/
routes/
bootstrap/
storage/
```

و تنظیم:

```text
APP
DB
Telegram
Connectix
Bot
```

---

# Phase 3 — Database

ساخت:

```text
Models
Migrations
Relationships
Casts
Scopes
```

---

# Phase 4 — Telegram Infrastructure

ساخت:

```text
TelegramService
TelegramWebhookController
Update Dispatcher
```

---

# Phase 5 — User / State

انتقال:

```text
getUser()
userInfo()
actionStep()
```

به معماری Laravel.

---

# Phase 6 — Connectix

انتقال کامل:

```text
Plans
Clients
Create
Update
Get
Seller API
```

---

# Phase 7 — Plan

انتقال کامل parser:

```text
parsePlanTitle
parseType
approximateDays
groups
device counts
flags
```

و تست آنها.

---

# Phase 8 — Purchase

پیاده‌سازی:

```text
buy
addAccount
checkout
renew
```

---

# Phase 9 — Payment

پیاده‌سازی:

```text
Card payment
Receipt
Admin approval
Payment status
Order number
```

---

# Phase 10 — Wallet

پیاده‌سازی:

```text
Wallet
Wallet transactions
Deposit
Deduct
History
```

---

# Phase 11 — Coupon

پیاده‌سازی کامل Coupon logic.

---

# Phase 12 — SMS Payment

پیاده‌سازی:

```text
SMS parser
Payment matcher
Expiration
Payment confirmation
```

---

# Phase 13 — Admin Panel

انتقال کامل Admin.

---

# Phase 14 — Sync

تبدیل Update/Sync scripts به Artisan Commands.

---

# Phase 15 — Broadcast

انتقال Broadcast.

---

# Phase 16 — Testing

برای Business Logic تست بنویس.

حداقل:

```text
User state
Plan parser
Coupon
Wallet
Payment
Client creation/update
Telegram callbacks
Purchase flow
Renewal flow
```

---

# 40. Tests

از PHPUnit/Pest موجود Laravel استفاده کن.

تست‌های Unit برای parserها و Serviceهای حساس.

تست Feature برای:

```text
Telegram webhook
Admin login
Payment
Purchase flow
```

در صورت امکان.

برای APIهای خارجی Mock ایجاد کن.

تست نباید به Connectix واقعی یا Telegram واقعی وابسته باشد.

---

# 41. Important Legacy Functions Mapping

این mapping را به عنوان راهنمای اولیه استفاده کن:

```text
tg()
    → TelegramService

getUser()
userInfo()
actionStep()
    → User / UserService / UserStateService

checkUserChannelJoin()
    → TelegramService / MembershipService

getDownloadLinks()
    → Guide/Download Service

wallet()
createWalletTransaction()
getWalletTransactions()
parseWalletTransactionsType()
parseWalletTransactionsStatus()
    → WalletService

checkCoupon()
discount()
    → CouponService

payment()
savePayment()
paycheck()
    → PaymentService

smsPayment()
    → SmsPaymentService

getClientByUsername()
getClientData()
createClient()
updateClient()
    → ConnectixService / Client Actions

getSellerPlans()
getSellerPlanGroupName()
getAvailableDeviceCountsFromPlans()
planMatchesGroup()
parsePlanTitle()
approximateDays()
parseType()
parseTypeWithEmoji()
    → PlanService

addAccount()
buy()
checkout()
renew()
    → Purchase / Renewal Actions

guide()
guideButton()
getGuideLink()
getCustomGuideItems()
    → GuideService

errorLog()
    → Laravel Log
```

این mapping قطعی نیست.

قبل از implementation، کد واقعی را بررسی کن.

---

# 42. Important Behavior Rules

در تمام مراحل:

## Rule 1

هیچ Business Logic را فقط به خاطر قدیمی بودن حذف نکن.

## Rule 2

هیچ Message Telegram را بدون بررسی Legacy تغییر نده.

## Rule 3

Callback dataها باید سازگار بمانند مگر اینکه migration strategy مشخص داشته باشی.

## Rule 4

Payment statusها را دقیقاً بررسی کن.

## Rule 5

Wallet balance نباید اشتباه شود.

## Rule 6

Order number generation باید سازگار باشد.

## Rule 7

Plan parser باید با Planهای واقعی Connectix کار کند.

## Rule 8

User state نباید در وسط purchase flow از بین برود.

## Rule 9

Renew و Buy را با هم قاطی نکن.

## Rule 10

External API errors باید قابل مدیریت باشند.

---

# 43. Do Not Overengineer

این پروژه را تبدیل به Microservice نکن.

نیازی نیست:

```text
CQRS کامل
Event Sourcing
DDD framework
Redis
RabbitMQ
Kafka
Docker
Kubernetes
```

اضافه کنی.

Laravel Modular Service Architecture کافی است.

---

# 44. Database Transactions

عملیات‌هایی که چند مرحله مالی/دیتابیسی دارند باید transaction-safe باشند.

مثلاً:

```text
Wallet deduction
Payment creation
Payment approval
```

در صورت مناسب بودن:

```php
DB::transaction()
```

استفاده کن.

اما API خارجی را کورکورانه داخل DB transaction طولانی قرار نده.

---

# 45. External API + Database

مثلاً Client creation:

```text
Validate
 ↓
Payment validation
 ↓
Call Connectix
 ↓
If success:
    Save local Client
    Mark payment successful
 ↓
Notify user
```

اگر API fail شد:

```text
Do not mark payment as successful
Do not create fake local client
Log error
Notify user/admin appropriately
```

رفتار واقعی Legacy را نیز در این تصمیم‌ها بررسی کن.

---

# 46. Configuration

Secretها:

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=
CONNECTIX_PANEL_TOKEN=

DB_HOST=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```

در `.env`.

و:

```text
.env
```

هرگز commit نشود.

`.env.example` باید بدون secret واقعی ساخته شود.

---

# 47. Important Security Issue

در Legacy ممکن است credentialهایی وجود داشته باشد که قبلاً در فایل config بوده‌اند.

آنها را به Git منتقل نکن.

اگر credential واقعی در repository یا history وجود دارد، آن را فقط با حذف فایل حل‌شده فرض نکن؛ باید در نظر بگیری که credential باید rotate/revoke شود.

---

# 48. Quality Requirements

کد نهایی باید:

* PSR-12
* Laravel conventions
* Type declarations در جاهای مناسب
* Return types
* Dependency Injection
* کوچک نگه داشتن Controllerها
* جلوگیری از duplicate code
* نام‌گذاری واضح
* Error handling
* Logging
* Validation

را رعایت کند.

---

# 49. Controllers

Controller نباید Business Logic سنگین داشته باشد.

بد:

```php
public function webhook(...)
{
    // 500 lines
}
```

خوب:

```php
public function webhook(...)
{
    return $this->dispatcher->dispatch($update);
}
```

و Logic در:

```text
Services
Actions
Handlers
```

باشد.

---

# 50. Do Not Break Existing Project

قبل از تغییر فایل مهم:

بررسی کن که چه چیزی به آن وابسته است.

خصوصاً:

```text
functions.php
config.php
bot.php
setup/
```

را بدون فهم کامل حذف نکن.

---

# 51. Final Goal

در پایان باید بتوانیم:

```text
Telegram
   ↓
Laravel Webhook
   ↓
Update Dispatcher
   ↓
User / State
   ↓
Purchase / Renewal
   ↓
Plan
   ↓
Payment / Wallet
   ↓
Connectix API
   ↓
Client
   ↓
Telegram response
```

و:

```text
Admin
   ↓
Laravel Admin Panel
   ↓
Users
Payments
Wallets
Clients
SMS Payments
Transactions
```

را داشته باشیم.

---

# 52. Deployment Goal

Application باید برای Shared Hosting آماده باشد.

ساختار deployment باید با:

```text
PHP
MySQL
Composer
cPanel
Cron
```

قابل اجرا باشد.

Document کن که:

```text
Document Root
.env
storage permissions
public/
webhook
cron
```

چگونه تنظیم می‌شوند.

---

# 53. مهم: قبل از Coding

قبل از اینکه implementation را شروع کنی:

1. کل Legacy را scan کن.
2. Dependencyها را مشخص کن.
3. تمام DB queries را بررسی کن.
4. تمام Telegram callbackها را پیدا کن.
5. تمام Payment statusها را پیدا کن.
6. تمام Plan parserها را پیدا کن.
7. تمام API endpointهای Connectix را پیدا کن.
8. تمام Admin routes/actions را پیدا کن.
9. تمام configها را پیدا کن.
10. تمام فایل‌هایی که Business Logic دارند را مشخص کن.

سپس یک mapping داخلی بساز.

**نیازی نیست برای تأیید من متوقف شوی.**

پس از بررسی، implementation را شروع کن.

---

# 54. اجرای مرحله‌ای

کار را به صورت مرحله‌ای انجام بده.

بعد از هر Phase:

```text
php artisan about
php artisan route:list
php artisan test
```

و هر تست/بررسی مرتبط دیگری را اجرا کن.

اگر خطایی پیدا شد، همان مرحله را اصلاح کن و بعد برو مرحله بعد.

---

# 55. عدم توقف برای سؤال‌های غیرضروری

اگر چیزی از Legacy قابل استخراج است، از من نپرس.

خودت:

* repository را بررسی کن
* کد را بخوان
* config را بررسی کن
* schema را استخراج کن
* dependency را پیدا کن
* implementation مناسب را انتخاب کن

فقط در مواردی که واقعاً تصمیم تجاری جدید لازم است و از Legacy قابل استخراج نیست، موضوع را مشخص کن.

---

# 56. گزارش بعد از هر مرحله

در پایان هر Phase کوتاه گزارش بده:

```text
Phase:
Status:

Implemented:
- ...
- ...
- ...

Files:
- ...
- ...

Tests:
- ...

Issues:
- ...

Next:
- ...
```

گزارش کوتاه باشد.

در گزارش، توضیح نده که چه Toolهایی استفاده کردی.

---

# 57. Definition of Done

Rewrite زمانی Done محسوب می‌شود که:

* Laravel application بدون error اجرا شود.
* Database models و migrations آماده باشند.
* Telegram Webhook کار کند.
* User creation/update کار کند.
* User state کار کند.
* Channel membership کار کند.
* Plan parsing کار کند.
* Plan groups کار کنند.
* Device selection کار کند.
* Buy flow کار کند.
* Renewal کار کند.
* Connectix API کار کند.
* Client creation کار کند.
* Client update کار کند.
* Payment کار کند.
* Card-to-card کار کند.
* Receipt flow کار کند.
* Admin approval کار کند.
* Wallet کار کند.
* Wallet transaction کار کند.
* Coupon کار کند.
* Test account کار کند.
* SMS payment کار کند.
* Broadcast کار کند.
* Sync commands کار کنند.
* Admin panel کار کند.
* Authentication کار کند.
* Logging مناسب باشد.
* Secrets خارج از source code باشند.
* Tests مربوط به Business Logic وجود داشته باشند.
* Shared hosting deployment قابل انجام باشد.

---

# 58. مهم‌ترین اصل کل پروژه

این پروژه یک **Refactor / Rewrite با حفظ رفتار** است، نه یک پروژه جدید.

یعنی:

```text
Legacy Behavior
       ↓
Understand
       ↓
Extract Business Logic
       ↓
Design Laravel equivalent
       ↓
Implement
       ↓
Test
       ↓
Parity
```

نه:

```text
Old PHP
   ↓
Delete
   ↓
Build something new
```

---

# 59. Git Workflow و Commit Strategy

این پروژه یک Rewrite بزرگ است؛ بنابراین Git history باید مرحله‌ای و قابل بازگشت باشد.

Branch اصلی توسعه:

```text
laravel-rewrite
```

نسخه Legacy فعلی:

```text
v3.3.6
```

این Tag نباید تغییر کند.

نسخه Laravel Rewrite در حال توسعه:

```text
4.0.0-dev
```

است.

فایل:

```text
version.txt
```

باید در طول Rewrite مقدار فعلی Development Version را نگه دارد.

---

## Commit Policy

بعد از تکمیل هر Phase منطقی، و فقط پس از اینکه تست‌ها و بررسی‌های همان Phase با موفقیت انجام شدند، یک Commit مستقل ایجاد کن.

برای تغییرات کوچک و مرتبط، Commit جداگانه نساز.

هدف این است که Git History تقریباً این ساختار را داشته باشد:

```text
v3.3.6
   ↓
chore: initialize Laravel 13 rewrite
   ↓
feat: add Laravel database layer
   ↓
feat: add Telegram infrastructure
   ↓
feat: migrate user state management
   ↓
feat: add Connectix service
   ↓
feat: migrate plan system
   ↓
feat: migrate purchase flow
   ↓
feat: migrate payment system
   ↓
feat: migrate wallet system
   ↓
feat: migrate coupon system
   ↓
feat: migrate admin panel
   ↓
feat: migrate bank SMS payments
   ↓
feat: migrate sync commands
   ↓
feat: migrate broadcast system
   ↓
test: add Laravel rewrite parity tests
   ↓
chore: prepare Laravel 4.0.0 release
```

نام دقیق Commit باید بر اساس کار واقعی انجام‌شده انتخاب شود.

---

## قبل از هر Commit

قبل از Commit:

1. `git status` را بررسی کن.
2. تغییرات را بررسی کن.
3. مطمئن شو Secret یا credential وارد Git نشده باشد.
4. `.env` نباید commit شود.
5. فایل‌های debug حاوی secret نباید commit شوند.
6. تست‌های مرتبط با Phase را اجرا کن.
7. اگر تستی fail شد، ابتدا مشکل را برطرف کن.
8. سپس Commit ایجاد کن.

---

## Commitهای ناقص ممنوع

Commit نباید شامل کدی باشد که:

* پروژه را خراب می‌کند
* Syntax Error دارد
* Laravel boot نمی‌شود
* تست‌های همان Phase را خراب می‌کند
* Secret واقعی دارد
* نصفه‌نیمه یک Feature را پیاده کرده ولی Phase را به عنوان کامل ثبت کرده

اگر یک Phase بزرگ است، می‌توان آن را به چند milestone منطقی تقسیم کرد.

---

## Push Policy

بعد از Commit موفق:

```bash
git push origin laravel-rewrite
```

انجام بده.

Branch اصلی `main` را در طول Rewrite تغییر نده، مگر اینکه صراحتاً درخواست شود.

---

## Tag Policy

برای هر Phase معمولی Tag نساز.

Tag فقط برای Milestoneهای مهم ساخته شود.

در طول توسعه:

```text
4.0.0-dev
```

کافی است.

وقتی Rewrite به یک وضعیت قابل تست جدی رسید:

```text
v4.0.0-rc.1
```

می‌توان ایجاد کرد.

وقتی Rewrite کامل، تست‌شده و آماده Production شد:

```text
v4.0.0
```

Tag نهایی ایجاد شود.

Tagهای Release را بدون دلیل روی هر Commit ایجاد نکن.

---

## Release Versioning

Versioning به شکل زیر باشد:

```text
Legacy:
v3.3.6

Laravel development:
4.0.0-dev

Release Candidate:
v4.0.0-rc.1

Production:
v4.0.0
```

تا زمانی که Rewrite کامل نشده، `v4.0.0` نهایی ایجاد نکن.

---

## مهم

هرگز Tag موجود:

```text
v3.3.6
```

را تغییر، حذف یا جابه‌جا نکن.

این Tag باید به عنوان آخرین نسخه سالم Legacy باقی بماند.

---

## گزارش Commit

در پایان هر Phase گزارش کوتاه بده:

```text
Phase:
Status: completed

Commit:
<commit hash>

Message:
<commit message>

Tests:
<test result>

Next Phase:
<next phase>
```

بعد از Commit موفق، بدون نیاز به تأیید من وارد Phase بعدی شو.

---

# 60. Master Progress Checklist

این Checklist مرجع اصلی وضعیت پیشرفت پروژه است.

## وضعیت فعلی

**Current Phase:** `Phase 9 — Payment System`
**Status:** `Phase 8 کامل · 197 تست، 474 assertion · آخرین commit: 44bad7e`
**Checklist:** `Phase 0 تا 8 کامل (یک مورد استثنا در Phase 6) · 197 تست، 474 assertion`
**Next Step:** `PaymentHandler` — رسید کارت به کارت، ساخت order، تطبیق با `wallet_transactions` و تأیید/رد توسط ادمین.

مستند Audit و Mapping: `docs/legacy-audit.md`

نکته‌هایی که در این فازها کشف و اصلاح شدند و نباید دوباره خراب شوند:

* `payments.is_paid` سه‌حالته است. با enum cast معمولی، مقدار `NULL` به‌جای enum مقدار `null` برمی‌گرداند و `isPending()` روی null صدا زده می‌شود. `Payment::isPaid()` این را نرمال می‌کند.
* متد `Payment::isPaid()` متد نیست، mutator است؛ صدا زدنش به query builder می‌رود و `BadMethodCallException` می‌دهد. برای وضعیت از `$payment->is_paid` یا `$payment->isPending()` استفاده کن. خواندن `is_paid` هیچ‌وقت `null` نیست.
* `Http::fake()` الگو را با **کل URL شامل query string** مقایسه می‌کند، پس `clients/show?id=*` لازم است. اولین stub منطبق برنده است، بنابراین stub عمومی نباید قبل از stub خاص ثبت شود. closure داخل fake نباید return type داشته باشد.
* `userInfo()` در Legacy اول `actionStep('clear')` را صدا می‌زند، پس `/start` و `main_menu` باید state را پاک کنند.
* `createWalletTransaction` در Legacy مبلغ را **مثبت** ذخیره می‌کند و جهت را در `operation=DECREASE` می‌گذارد.
* `addAccount` در Legacy فقط `chat_id` و `user_id` را در `clients` آپدیت می‌کند؛ `created_at` و credentialهای محلی نباید بازنویسی شوند.
* `addSelect(['alias' => 'table.column'])` Alias را دور می‌ریزد؛ باید `DB::raw('table.column as alias')` نوشت.
* فیلتر دانلود لیگاسی کلید **کوچک** را به نام **بزرگ‌حرف** نگاشت می‌کند و هر ورودی دیگری فیلتر نمی‌کند.
* مرتب‌سازی guide ها با `strnatcasecmp` است، پس `intro` قبل از `Setup` می‌آید.
* مقایسه تاریخ کoupon **رشته‌ای** است و فقط `null` واقعی را رد می‌کند؛ رشته خالی یعنی منقضی‌شده.
* `config:cache` باید بعد از تغییر `config/connectix_bot.php` دوباره اجرا شود.

**قوانین اجباری:**

* قبل از شروع هر فاز، وضعیت Checklist را بررسی کن.
* فقط وقتی یک فاز واقعاً کامل شده، `[ ]` آن را به `[x]` تغییر بده.
* اگر فاز ناقص است، آن را تیک نزن؛ حتی اگر بخش زیادی از آن انجام شده باشد.
* بعد از اتمام هر فاز:

  1. تست‌های مربوط به همان فاز را اجرا کن.
  2. خطاهای مهم را برطرف کن.
  3. `git diff --check` را اجرا کن.
  4. `git status` و `git diff` را بررسی کن.
  5. مطمئن شو Secret یا Credential وارد Git نشده است.
  6. تغییرات همان فاز را Commit کن.
  7. Commit را روی branch فعلی `laravel-rewrite` Push کن.
  8. سپس Checklist را آپدیت کن.
  9. بعد به فاز بعدی برو.
* به `main` دست نزن.
* روی `main` merge نکن.
* Force Push ممنوع است.
* Tag Release نساز مگر اینکه صراحتاً درخواست شده باشد.
* اگر Agent به دلیل compact شدن گفتگو ادامه کار را از سر گرفت، **ابتدا همین Checklist را بخوان و از آخرین `[x]` ادامه بده**.
* هرگز کاری را که قبلاً `[x]` شده بدون دلیل معتبر دوباره از ابتدا انجام نده.
* اگر فازی به دلیل وابستگی به فاز دیگری قابل تکمیل نیست، آن را تیک نزن و علت را در گزارش بنویس.
* اگر در حین کار نیاز به تغییر معماری مهمی پیدا شد، قبل از اعمال آن، ساختار فعلی پروژه و این Checklist را در نظر بگیر و از ایجاد معماری موازی یا تکراری خودداری کن.

---

## Phase 0 — Laravel Foundation

* [x] Laravel 13 در Root پروژه نصب و فعال شد.
* [x] ساختار Laravel با Legacy Project ادغام شد.
* [x] `composer.json` و `composer.lock` صحیح هستند.
* [x] `.gitignore` برای Laravel + Legacy تنظیم شد.
* [x] `storage:link` ایجاد شد.
* [x] Laravel با `php artisan about` اجرا می‌شود.
* [x] `php artisan route:list` بدون خطای Fatal اجرا می‌شود.
* [x] `version.txt` روی `4.0.0-dev` تنظیم شده است.
* [x] تغییرات Foundation Commit و Push شده‌اند.

**Commit پیشنهادی:**
`chore: initialize Laravel 13 rewrite`

---

## Phase 1 — Legacy Audit & Architecture

* [x] کل Legacy Project بررسی شد.
* [x] تمام فایل‌های PHP مهم بررسی شدند.
* [x] تمام جدول‌های Database و روابط آن‌ها مستندسازی شدند.
* [x] تمام Flowهای اصلی Bot شناسایی شدند.
* [x] تمام Admin Flowهای مهم شناسایی شدند.
* [x] تمام Payment Flowها شناسایی شدند.
* [x] Wallet Flow کاملاً بررسی شد.
* [x] Coupon Flow کاملاً بررسی شد.
* [x] Connectix API Flow کاملاً بررسی شد.
* [x] Plan Parser و قوانین Planها بررسی شدند.
* [x] User State / `actionStep` کاملاً بررسی شد.
* [x] Telegram Update / Callback Flow کاملاً بررسی شد.
* [x] Sync Scriptها بررسی شدند.
* [x] Bank SMS Flow بررسی شد.
* [x] Broadcast Flow بررسی شد.
* [x] Guide / Download Link Flow بررسی شد.
* [x] Configuration و `bot_config.json` بررسی شدند.
* [x] وابستگی‌های Legacy به `functions.php` شناسایی شدند.
* [x] هیچ Business Logic مهمی بدون بررسی حذف نشده است.
* [x] معماری نهایی Laravel بر اساس Audit مشخص شده است.
* [x] نتیجه Audit و Mapping Legacy → Laravel مستند شده است (`docs/legacy-audit.md`).
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`docs: complete legacy architecture audit`

---

## Phase 2 — Environment & Configuration

* [x] `.env.example` کامل و صحیح ایجاد شد.
* [x] Configurationهای Legacy به ساختار مناسب Laravel منتقل شدند.
* [x] Bot Token از Environment/Config امن خوانده می‌شود.
* [x] Connectix API credentials امن منتقل شدند.
* [x] Database configuration آماده است.
* [x] Telegram configuration آماده است.
* [x] Admin configuration آماده است.
* [x] Bank/Payment configuration آماده است.
* [x] هیچ Secret در Repository وجود ندارد.
* [x] Configurationهای Legacy که هنوز مورد نیازند حفظ شده‌اند.
* [x] Environmentهای Local / Production قابل تفکیک هستند.
* [x] Laravel در Environment جدید بدون خطای Configuration اجرا می‌شود.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: configure Laravel environment`

---

## Phase 3 — Database & Eloquent Models

* [x] Migrationهای لازم برای Database ایجاد شدند.
* [x] `users` Model ایجاد و بررسی شد.
* [x] `admins` Model ایجاد و بررسی شد.
* [x] `clients` Model ایجاد و بررسی شد.
* [x] `payments` Model ایجاد و بررسی شد.
* [x] `wallets` Model ایجاد و بررسی شد.
* [x] `wallet_transactions` Model ایجاد و بررسی شد.
* [x] `sms_payments` Model ایجاد و بررسی شد.
* [x] Relationships صحیح تعریف شدند.
* [x] Castها و Attributeهای لازم تعریف شدند.
* [x] Compatibility با Legacy Schema بررسی شد.
* [x] داده‌های Legacy قابل Migration هستند.
* [x] Migrationها روی Database تست شدند.
* [x] هیچ داده‌ای در Migrationها بدون دلیل تخریب نمی‌شود.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: add database schema and eloquent models`

---

## Phase 4 — Core Services

* [x] `TelegramService` ایجاد شد.
* [x] `ConnectixService` ایجاد شد.
* [x] `PaymentService` ایجاد شد.
* [x] `WalletService` ایجاد شد.
* [x] `CouponService` ایجاد شد.
* [x] `PlanService` ایجاد شد.
* [x] `UserStateService` ایجاد شد.
* [x] Guide/Download Service ایجاد شد.
* [x] Error/Logging Service یا ساختار مناسب Logging ایجاد شد.
* [x] Business Logic از Controllerها خارج شده است.
* [x] `functions.php` به God Service جدید تبدیل نشده است.
* [x] Serviceها مسئولیت مشخص و محدود دارند.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`refactor: extract core business services`

---

## Phase 5 — Telegram Infrastructure

* [x] Telegram Webhook Endpoint ایجاد شد.
* [x] Webhook Secret Validation پیاده‌سازی شد.
* [x] Telegram Update Dispatcher ایجاد شد.
* [x] Message Update Handling پیاده‌سازی شد.
* [x] Callback Query Handling پیاده‌سازی شد.
* [x] Command Handling پیاده‌سازی شد.
* [x] User Creation/Update پیاده‌سازی شد.
* [x] Telegram Profile/Avatar Handling پیاده‌سازی شد.
* [x] Channel Membership Check پیاده‌سازی شد.
* [x] Action State Handling پیاده‌سازی شد.
* [x] Keyboard Builder پیاده‌سازی شد.
* [x] Message Template/Variable Replacement پیاده‌سازی شد.
* [x] Error Handling Telegram پیاده‌سازی شد.
* [x] Bot بدون Business Flow پیچیده قابل دریافت و Dispatch کردن Update است.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: implement telegram webhook infrastructure`

---

## Phase 6 — User & State Management

* [x] User lookup/create/update کامل شد.
* [x] Telegram ID / Chat ID mapping حفظ شد.
* [x] User state migration انجام شد.
* [x] `actionStep` behavior معادل Legacy شد.
* [x] Stateهای خراب/نامعتبر مدیریت می‌شوند.
* [x] Reset State پیاده‌سازی شد.
* [x] User profile data handling کامل شد.
* [x] Test Account state handling کامل شد. (فلگ در `FreeTestHandler` با `forceFill(['test' => true])` اعطا، در تست مصرف و در برابر درخواست تکراری مسدود می‌شود — پوشش `FreeTestFlowTest`.)
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: implement user state management`

---

## Phase 7 — Plan & Connectix Integration

* [x] Connectix API Client کامل شد.
* [x] Authentication با Connectix API کامل شد.
* [x] Seller Plans دریافت می‌شوند.
* [x] Plan Group handling کامل شد.
* [x] Device Count parsing کامل شد.
* [x] Traffic parsing کامل شد.
* [x] Duration parsing کامل شد.
* [x] Gift Days parsing کامل شد.
* [x] Free/Test Plan handling کامل شد.
* [x] Sublink handling کامل شد.
* [x] Economic handling کامل شد.
* [x] Static IP handling کامل شد.
* [x] Iran Access handling کامل شد.
* [x] Business Class handling کامل شد.
* [x] BCSublink handling کامل شد.
* [x] Client Creation کامل شد.
* [x] Client Update/Renewal کامل شد.
* [x] Client lookup کامل شد.
* [x] Plan Parser با Legacy behavior تطبیق داده شد.
* [x] Error handling API کامل شد.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: implement connectix plans and client integration`

---

## Phase 8 — Purchase Flow

* [x] New Account Flow پیاده‌سازی شد.
* [x] Renewal Flow پیاده‌سازی شد.
* [x] Plan Group Selection پیاده‌سازی شد.
* [x] Device Selection پیاده‌سازی شد.
* [x] Plan Selection پیاده‌سازی شد.
* [x] Order Creation پیاده‌سازی شد.
* [x] Price Calculation پیاده‌سازی شد.
* [x] Coupon Application پیاده‌سازی شد.
* [x] Payment Method Selection پیاده‌سازی شد.
* [x] Checkout پیاده‌سازی شد.
* [x] Successful Purchase Flow کامل شد.
* [x] Failed Purchase Flow کامل شد.
* [x] Cancel/Back Flow کامل شد.
* [x] Duplicate Purchase handling بررسی شد.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: implement purchase and renewal flow`

---

## Phase 9 — Payment System

* [x] Card-to-Card Payment Flow پیاده‌سازی شد.
* [x] Receipt Upload/Processing پیاده‌سازی شد.
* [x] Admin Payment Approval پیاده‌سازی شد.
* [x] Payment Status handling کامل شد.
* [x] Payment Expiration handling کامل شد.
* [x] Wallet Payment پیاده‌سازی شد.
* [x] Wallet Balance Validation پیاده‌سازی شد.
* [x] Wallet Deduction اتمیک و امن شد.
* [x] Wallet Transaction ایجاد می‌شود.
* [x] Payment Transaction ایجاد می‌شود.
* [x] SMS Payment Flow پیاده‌سازی شد.
* [x] Bank SMS Parsing پیاده‌سازی شد.
* [x] SMS → Pending Payment Matching پیاده‌سازی شد.
* [x] Duplicate SMS/payment handling پیاده‌سازی شد.
* [x] Failed Payment handling کامل شد.
* [x] Financial operations بدون Double Charge انجام می‌شوند.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: implement payment and wallet system`

---

## Phase 10 — Coupon System

* [x] Coupon lookup پیاده‌سازی شد.
* [x] Coupon validity بررسی می‌شود.
* [x] Start/End Date بررسی می‌شود.
* [x] Plan restrictions بررسی می‌شود.
* [x] Discount calculation تطبیق داده شد.
* [x] Invalid coupon handling کامل شد.
* [x] Expired coupon handling کامل شد.
* [x] Coupon usage behavior با Legacy تطبیق داده شد.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: implement coupon system`

---

## Phase 11 — Test Account & Guides

* [x] Free Test Account Flow پیاده‌سازی شد.
* [x] محدودیت دریافت Test Account حفظ شد.
* [x] Test Account creation با Connectix کامل شد.
* [x] Guide system پیاده‌سازی شد.
* [x] Platform-specific guide handling کامل شد.
* [x] Custom Guide items پشتیبانی می‌شوند.
* [x] Download Links handling پیاده‌سازی شد.
* [x] Download Link scraping behavior بررسی و ایمن‌سازی شد.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: implement test accounts and guides`

---

## Phase 12 — Admin Authentication & Authorization

* [x] Admin Model کامل شد.
* [x] Admin Login پیاده‌سازی شد.
* [x] Password hashing امن است.
* [x] Session/Auth handling پیاده‌سازی شد.
* [x] Admin token handling بررسی شد.
* [x] Role handling (`admin` / `editor`) پیاده‌سازی شد.
* [x] Authorization Middleware پیاده‌سازی شد.
* [x] Unauthorized access handling کامل شد.
* [x] CSRF protection برای Web Forms فعال است.
* [x] Logout کامل شد.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: implement admin authentication and authorization`

---

## Phase 13 — Admin Panel

* [x] Dashboard پیاده‌سازی شد.
* [x] User List پیاده‌سازی شد.
* [x] User Search پیاده‌سازی شد.
* [x] User Profile پیاده‌سازی شد.
* [x] Client Details پیاده‌سازی شد.
* [x] Payment Transactions پیاده‌سازی شد.
* [x] Wallet Transactions پیاده‌سازی شد.
* [x] SMS Payments پیاده‌سازی شد.
* [x] Payment Approval پیاده‌سازی شد.
* [x] Bot Configuration UI پیاده‌سازی شد.
* [x] Guide Management پیاده‌سازی شد.
* [x] Broadcast UI پیاده‌سازی شد.
* [x] Admin role restrictions اعمال شد.
* [x] Legacy Admin Panel functionality بررسی و پوشش داده شد.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: implement laravel admin panel`

---

## Phase 14 — Broadcast System

* [x] Broadcast creation پیاده‌سازی شد.
* [x] Recipient selection پیاده‌سازی شد.
* [x] Message sending پیاده‌سازی شد.
* [x] Progress tracking پیاده‌سازی شد.
* [x] Failed recipient handling پیاده‌سازی شد.
* [x] Rate limiting مناسب Telegram رعایت شد.
* [x] Broadcast state persistence پیاده‌سازی شد.
* [x] Shared-hosting compatible implementation استفاده شد.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: implement broadcast system`

---

## Phase 15 — Sync & Background Tasks

* [x] Client Sync Command پیاده‌سازی شد.
* [x] User Sync/Update handling پیاده‌سازی شد.
* [x] Bot Update/maintenance handling بررسی شد.
* [x] Bank SMS processing command/job پیاده‌سازی شد.
* [x] Scheduled Tasks تعریف شدند.
* [x] Shared-hosting compatible execution method مشخص شد.
* [x] Cron documentation آماده شد.
* [x] Commands بدون duplicate processing کار می‌کنند.
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: implement sync and scheduled tasks`

---

## Phase 16 — Legacy Data Migration

* [x] Migration strategy برای Legacy Database نهایی شد. (نام جدول‌ها و ستون‌ها یکی است، پس نصب روی همان دیتابیس قبلی هیچ importی نمی‌خواهد؛ `legacy:import` فقط برای دیتابیس تازه است و فقط insert می‌کند.)
* [x] Users migration تست شد.
* [x] Admins migration تست شد.
* [x] Clients migration تست شد.
* [x] Payments migration تست شد.
* [x] Wallets migration تست شد.
* [x] Wallet Transactions migration تست شد.
* [x] SMS Payments migration تست شد.
* [x] Foreign/Logical relationships بررسی شدند. (`users.chat_id` و `wallets.chat_id` کلید طبیعی‌اند، `clients.id` کلید panel است و `wallet_transactions`/`sms_payments` با کلید اصلی خودشان؛ هیچ چیز update یا delete نمی‌شود.)
* [x] Record counts قبل و بعد مقایسه شدند. (`legacy:verify` قبل و بعد از import تست شد.)
* [x] Data integrity بررسی شد. (سطر بدون کلید طبیعی شمرده، لاگ و رد می‌شود؛ هرگز بی‌صدا حذف نمی‌شود.)
* [x] Migration روی Copy/Backup تست شد. (روی یک کپی SQLite از دیتابیس legacy اجرا و شمارش‌ها مقایسه شد؛ روی MySQL واقعی هنوز نه — آن در Checklist فاز 21 می‌ماند.)
* [x] Rollback strategy مشخص شد. (هیچ حذفی انجام نمی‌شود؛ rollback یعنی بازگرداندن backup قبل از اجرا. `legacy:import` بدون `--confirm` فقط dry-run است.)
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`feat: add legacy data migration`

---

## Phase 17 — Security Hardening

* [x] تمام Secrets از Source Code خارج شدند. (اسکن کل source ردیابی نشد؛ `config.example.php` به placeholderهای `<...-token>` تغییر کرد؛ `config.php` و `debug/tel.php` در git نیستند؛ لاگ‌ها از `LogRedaction` عبور می‌کنند و bindings کوئری با `mask_bindings_in_exception_messages` ماسک می‌شوند.)
* [x] `.env` در Git نیست.
* [x] Bot Token rotate/revoke شده و Secret جدید امن است. (توکن فقط در `config.php` (gitignored) و `.env` بوده و هرگز commit نشده؛ کاربر تأیید کرد rotate لازم نیست.)
* [x] Connectix credentials امن هستند. (فقط در `config.php`/`.env`؛ هرگز در git یا لاگ نبوده‌اند.)
* [x] Telegram Webhook Secret validation فعال است. (گارد `VerifyTelegramWebhook` حالا روی secret خالی هم fail-closed است: 403 به‌جای allow.)
* [x] CSRF protection بررسی شد.
* [x] Authentication بررسی شد. (تک hash check با `DECOY_HASH` ضد user-enumeration، لاگ فقط email+IP، `throttle:admin-login` پنج تلاش در دقیقه، کوکی remember با `Secure` پویا از `isSecure()`.)
* [x] Authorization بررسی شد. (پسورد client فقط برای نقش admin؛ `broadcast.progress` فقط admin؛ گارد `Sec-Fetch-Site`؛ گارد session روی `broadcast_progress.php` و `users/profile.php`.)
* [x] SQL Injection vectors بررسی شدند. (audit: فقط ۳ `DB::raw` ایستا، همه پارامتری.)
* [x] XSS vectors بررسی شدند. (Blade تمیز بود؛ خروجی‌های خام `users/profile.php|user.php|index.php` escape شد + گیت auth روی profile + `userPic` فقط https.)
* [x] File Upload validation بررسی شد. (`MEDIA_RULES` با mimes+mimetypes+10MB و نام فایل سروری؛ legacy `broadcast_start.php` با allow-list+50MB+نام سروری و رد صریح JSON؛ تست‌های `SecurityHardeningTest`.)
* [x] Receipt Upload validation بررسی شد. (رسیدها هرگز ذخیره نمی‌شوند — به‌صورت عکس تلگرامی فوروارد می‌شوند، مطابق legacy.)
* [x] Path Traversal بررسی شد. (`GuideService` مسیر callback data را فقط با الگوی `[A-Za-z0-9_-]+` به فایل تبدیل می‌کند و مسیر resolve‌شده را داخل پوشه guide تأیید می‌کند.)
* [x] SSRF/Unsafe URL fetching بررسی شد. (`DownloadLinkService` با timeout، user agent، TLS فعال اجرا می‌شود و loopback، private range و scheme غیر http را رد می‌کند.)
* [x] cURL SSL verification غیرفعال نیست مگر با دلیل موجه.
* [x] Debug endpointهای Legacy عمومی نیستند. (`.htaccess` ریشه درخت‌های ابزار و `.env*`/`config.php`/`*.sql`/`*.log` را deny می‌کند و `debug/.htaccess` کل پوشه را می‌بندد؛ استثناهای `.gitignore` برای `debug/.htaccess` و `broadcast/uploads/.htaccess` اضافه شد.)
* [x] Sensitive logs حذف/محافظت شدند. (`LogRedaction::mask` روی همه transport/exceptionها + ماسک digit-runهای ۱۲+ رقمی در لاگ bank SMS + `zend.exception_ignore_args` وقتی debug خاموش است.)
* [x] Production Debug خاموش است. (`config/app.php` پیش‌فرض `APP_ENV=production` و `APP_DEBUG=false` دارد؛ `.env.example` راهنما اضافه شد؛ suite با `CONNECTIX_SETUP_ENFORCE=false` ایزوله است.)
* [x] Security headers/secure cookies بررسی شدند. (`SecurityHeaders` با nosniff/DENY/referrer/Permissions-Policy/CSP و HSTS فقط روی TLS؛ `SESSION_SECURE_COOKIE` در `.env.example` مستند شد.)
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`security: harden application`

**نتیجه:** `php artisan test` = 395 تست، 1242 assertion؛ Pint پاس؛ ۱۷ تست جدید
(۹ `SecurityHardeningTest` + ۸ `LogRedactionTest`).

---

## Phase 18 — Testing & Legacy Parity

* [x] PHPUnit/Pest test infrastructure آماده است.
* [x] Model tests نوشته شدند.
* [x] Service tests نوشته شدند.
* [x] Plan Parser tests نوشته شدند.
* [x] Coupon tests نوشته شدند.
* [x] Wallet tests نوشته شدند.
* [x] Payment tests نوشته شدند.
* [x] Telegram Update tests نوشته شدند.
* [x] Purchase Flow tests نوشته شدند.
* [x] Renewal Flow tests نوشته شدند.
* [x] Test Account tests نوشته شدند.
* [x] Admin Auth tests نوشته شدند.
* [x] Critical API failure tests نوشته شدند.
* [x] Duplicate Payment tests نوشته شدند.
* [x] Edge Cases مهم تست شدند.
* [x] `php artisan test` موفق است. (378 تست، 1184 assertion — شامل 34 تست Setup. یک failure قدیمی در `TelegramGatewayTest` که به fetch برندینگ از پنل می‌رسید و fake نداشت نیز اصلاح شد. Phase 17 این عدد را به 395 تست و 1242 assertion رساند. Phase 18 با تست‌های E2E وب‌هوک، فیل‌های failure/edge و پوشش parity به 430 تست و 1381 assertion رسید — صفر failure. یک باگ بحرانی نیز در همین فاز رفع شد: `UserService::sync` در هر آپدیت state را پاک می‌کرد و هیچ flow چندمرحله‌ای از webhook کار نمی‌کرد.)
* [x] Legacy behavior با Laravel behavior مقایسه شد. (مستند در `docs/legacy-audit.md` بخش 19؛ شامل رفع پاک‌سازی state در هر آپدیت، claim شدن `new_menu` برای کیبوردهای قدیمی، منطق dedup پرچم تست، فیکس دوپیامی FreeTest، پاسخ callback در شکست پنل، کوکی‌های ادمین و divergences باقی‌مانده.)
* [x] Critical business flows بدون Regression هستند. (`EndToEndPurchaseTest` خرید کارت از `/start` تا `payment_accept` را از مسیر webhook واقعی تست می‌کند.)
* [x] Commit و Push انجام شد. (`e58bcd2` روی `laravel-rewrite`)

**Commit پیشنهادی:**
`test: add coverage and verify legacy parity`

---

## Phase 19 — Production / Shared Hosting Preparation

* [x] Production `.env.example` کامل است. (کلیدهای wizard + DB + APP_KEY/APP_URL/LOG اضافه شد؛ تست `EnvExampleTest` کاملی آن را پین می‌کند.)
* [x] Shared Hosting deployment structure مستند شد. (`docs/deployment.md` بخش ۱-۲)
* [x] Document Root / `public` configuration مشخص شد. (دو شکل: `public/` خالص و درخت ترکیبی legacy/Laravel با `.htaccess` ریشه)
* [x] Storage permissions بررسی شد. (`php artisan about` → LINKED؛ جدول مسیرهای نوشتنی در راهنما)
* [x] `storage:link` deployment handling مشخص شد. (دستور idempotent در deploy؛ خارج از wizard — مستند و تست‌شده با about)
* [x] Composer deployment strategy مستند شد. (`composer setup` + بارگذاری بدون shell)
* [x] `vendor` deployment strategy مستند شد. (ترتیب آپلود، `--no-dev`، autoload آخر)
* [x] Database migration instructions آماده شد. (`migrate --force`، --pretend dry-run، legacy:import/--dry-run)
* [x] Cron configuration آماده شد. (یک خط schedule:run + فهرست تسک‌ها)
* [x] Telegram Webhook configuration آماده شد. (`telegram:webhook set|info|remove` + قواعد secret/https)
* [x] Cache/config optimization بررسی شد. (config:cache راه‌اندازی، حکم config:clear قبل از تست، EnvWriter باز-کش می‌کند)
* [x] Production logging بررسی شد. (LOG daily در env.example، LogRedaction، محل grep کردن خطاهای webhook)
* [x] Backup strategy مستند شد. (دیتابیس/`.env`/connectix state — deployment.md §12)
* [x] Rollback strategy مستند شد. (webhook detach → کد → دیتابیس — deployment.md §13)
* [x] Deployment guide ایجاد شد. (`docs/deployment.md`)
* [x] Commit و Push انجام شد.

**Commit پیشنهادی:**
`docs: add production deployment guide`

---

## Phase 20 — Final Legacy Cutover

**این فاز فقط بعد از اطمینان از Parity انجام شود.**

* [x] Laravel Bot تمام Flowهای اصلی Legacy را پوشش می‌دهد. (دکمه‌های تلگرام و پاسخ‌های ربات توسط کاربر تأیید شد.)
* [x] Production Database migration نهایی بررسی شد. (روی MySQL زنده اعمال است و همه داده‌های واقعی روی آن‌اند.)
* [x] Backup کامل Legacy گرفته شد. (کد legacy دست‌نخورده در درخت کاری و git؛ آرشیو کد در `C:\xampp\backup-connectix\`.)
* [x] Backup Database گرفته شد. (`C:\xampp\backup-connectix\connectix_bot-20260930-101256.sql` + `.env-20260930-101256`.)
* [x] Telegram Webhook به Laravel منتقل شد. (200 روی لوکال و دامنه عمومی؛ `/start` زنده پیام داد.)
* [x] Production Configuration بررسی شد. (`.env` پروداکشن، debug خاموش، https، بدون نشت debug page.)
* [x] Payment Flow در Production بررسی شد. (تست واقعی timeout سفارش CX26093001 را آشکار کرد، retry اضافه شد و پذیرش مجدد با موفقیت اکانت ساخت.)
* [x] Connectix API در Production بررسی شد. (توکن زنده، GET retry پس از timeout، endpoint لیست کلاینت‌ها از `/v1/seller` به `/v1/seller/clients` اصلاح شد.)
* [x] Admin Panel در Production بررسی شد. (لاگین و POST تنظیمات زنده تأیید شد.)
* [x] Broadcast بررسی شد. (تست ارسال به چت ادمین با موفقیت انجام شد.)
* [x] Sync/Scheduled Tasks بررسی شد. (تسک زمان‌بندی با PHP 8.5 بازسازی و اجرای دستی شد؛ sync کلاینت‌ها تست شد.)
* [x] Error Logging بررسی شد. (خطای timeout پرداخت در `production.log` ثبت و ریشه‌یابی شد.)
* [x] چند سناریوی واقعی End-to-End اجرا شد. (پذیرش پرداخت، تمدید اکانت منقضی، لاگین ادمین و براودکست تست تأیید شد.)
* [x] Legacy Bot به عنوان Fallback نگه داشته شد. (کد legacy سر جایش است؛ هدایت webhook به آدرس legacy = برگشت.)
* [x] Legacy Code هنوز حذف نشده و قابل Rollback است.
* [x] Cutover موفق تأیید شد. (کاربر هر ۳ بازتست را موفق اعلام کرد.)
* [x] Commit و Push انجام شد. (`ca8e4c2`)

**Commit پیشنهادی:**
`feat: complete Laravel production cutover`

---

## Phase 21 — Release Candidate

* [x] تمام Phaseهای قبلی `[x]` هستند. (اسکن checklist: هیچ آیتم `[ ]` قبل از Phase 21 باقی نمانده.)
* [x] هیچ Critical/High bug شناخته‌شده‌ای باقی نمانده است. (timeout پرداخت، تمدید اکانت منقضی و endpoint لیست کلاینت‌ها اصلاح و بازتست شدند.)
* [x] تمام تست‌های اصلی موفق هستند. (455 تست، 1526 assertion + Pint پاس.)
* [x] Production smoke test موفق است. (لوکال `/` و `/admin/login` = 200؛ دامنه عمومی = 200؛ webhook بدون secret = 403 fail-closed.)
* [x] Deployment documentation کامل است. (`docs/deployment.md` §1–§15.)
* [x] Rollback documentation کامل است. (`docs/deployment.md` §13.)
* [x] Version در `version.txt` روی `4.0.0-rc.1` تنظیم شد.
* [x] Commit Release Candidate انجام شد. (`6fa49a8`.)
* [x] Tag `v4.0.0-rc.1` ساخته و Push شد.

---

## Phase 22 — Final Release v4.0.0

* [x] RC در Production بدون مشکل جدی اجرا شده است. (کد RC همان وضعیت زنده production است؛ 455 تست سبز و smoke عمومی موفق.)
* [x] Feedback و Bugهای RC بررسی شدند. (سه گزارش کاربر: timeout پرداخت → retry؛ «اکانت پلن نداره» → latestPlan؛ لاگین/ویزارد → پنجره دیسک پر — هر سه فیکس و بازتست شد.)
* [x] تمام Critical/High issues بسته شدند.
* [x] تست نهایی اجرا شد. (455 تست، 1526 assertion + Pint پاس + `composer validate` موفق.)
* [x] `version.txt` روی `4.0.0` تنظیم شد.
* [x] Changelog نهایی ایجاد شد. (`CHANGELOG.md`.)
* [x] Release Commit ایجاد شد. (`34bfd42`.)
* [x] Tag `v4.0.0` ساخته شد.
* [x] Tag به GitHub Push شد.
* [x] GitHub Release برای `v4.0.0` ایجاد شد. (https://github.com/MehdiSalari/Connectix-Bot/releases/tag/v4.0.0)
* [x] Release notes کامل هستند. (بدنه release از `CHANGELOG.md` + نتایج تست.)
* [x] وضعیت نهایی پروژه مستند شد. (`docs/deployment.md` §16 — Final release record.)

---

# Final Verification Checklist

قبل از اعلام اینکه پروژه کامل شده است:

* [x] `php artisan about` موفق است. (production، debug OFF، Laravel 13.33 / PHP 8.5.8.)
* [x] `php artisan route:list` موفق است. (۴۱ مسیر.)
* [x] `php artisan test` موفق است. (455 تست، 1526 assertion.)
* [x] `composer validate` موفق است.
* [x] `git diff --check` موفق است.
* [x] `git status` بررسی شده است. (درخت تمیز؛ `git describe` = `v4.0.0`.)
* [x] هیچ Secret در Git وجود ندارد. (اسکن: فقط توکن نمونه مستندات Laravel در تست redaction؛ پسورد یافت نشد؛ `.env`/`config.php` در git نیستند؛ `.env.example` خالی است.)
* [x] هیچ فایل Debug حساس در Production قابل دسترسی نیست. (`/.env`، `/config.php`، `/debug/tel.php`، `/debug/` همه 404.)
* [x] Database migration بررسی شده است. (§15: `migrate:status` همه اجرا شده؛ `--pretend` چیزی برای اجرا ندارد.)
* [x] Telegram webhook بررسی شده است. (۲۰۰ امضاشده دستی + بدون secret امروز = 403 fail-closed.)
* [x] Connectix API بررسی شده است. (توکن زنده، GET retry، endpoint لیست کلاینت‌ها اصلاح شد.)
* [x] Purchase Flow بررسی شده است. (پذیرش موفق CX26093001 توسط کاربر.)
* [x] Renewal Flow بررسی شده است. (بازتست موفق «تمدید» روی knt0oaoa.)
* [x] Wallet بررسی شده است. (WalletServiceTest سبز.)
* [x] Payment بررسی شده است. (یک پرداخت واقعی روی production تکمیل شد.)
* [x] Coupon بررسی شده است. (CouponServiceTest سبز.)
* [x] Test Account بررسی شده است. (FreeTestFlowTest سبز.)
* [x] Admin Panel بررسی شده است. (لاگین زنده + POST تنظیمات.)
* [x] Broadcast بررسی شده است. (تست به چت ادمین رسید.)
* [x] Sync بررسی شده است. (تست‌های SyncAndBackground + تسک زمان‌بندی زنده.)
* [x] Production Deployment بررسی شده است. (§1–§15 اجرا و ثبت شد.)
* [x] Rollback بررسی شده است. (§13 + تگ `v3.3.6` به‌عنوان هدف rollback.)
* [x] `version.txt` با Release نهایی هماهنگ است. (`4.0.0`.)
* [x] Git Tag با Version نهایی هماهنگ است. (`v4.0.0` روی `34bfd42`.)
* [x] پروژه آماده Release است. (GitHub Release منتشر شد.)

---

# Agent Resume Rule

اگر گفتگو compact شد، context از بین رفت، یا Agent دوباره وارد پروژه شد:

1. ابتدا فایل‌ها و وضعیت فعلی Git را بررسی کن.
2. این Checklist را پیدا و مطالعه کن.
3. آخرین Phaseای که `[x]` شده را پیدا کن.
4. وضعیت واقعی Repository را با Checklist مقایسه کن.
5. اگر Checklist با وضعیت واقعی مغایرت دارد، **بر اساس وضعیت واقعی پروژه** تصمیم بگیر و Checklist را اصلاح کن.
6. اولین Phase ناقص (`[ ]`) را مشخص کن.
7. فقط همان Phase و وابستگی‌های لازم را ادامه بده.
8. Phaseهای قبلی را بدون دلیل دوباره پیاده‌سازی نکن.
9. پس از تکمیل Phase، تست + بررسی Git + Commit + Push را انجام بده و سپس `[x]` را ثبت کن.
10. در گزارش خود همیشه بنویس:

**Current Phase:** `Phase X — ...`
**Status:** `IN PROGRESS / BLOCKED / COMPLETED`
**Checklist:** `X/Y items completed`
**Next Step:** `...`

**مهم:** این Checklist منبع اصلی وضعیت پروژه است؛ به حافظه گفتگو، پیام‌های قبلی یا فرضیات قبلی درباره اینکه چه کاری انجام شده، تکیه نکن. وضعیت واقعی Repository و این Checklist را مبنا قرار بده.


---

# شروع کار

همین حالا کار را شروع کن.

ابتدا کل repository و Legacy code را بررسی کن.

بعد Laravel architecture را بر اساس کد واقعی پروژه تکمیل کن.

سپس implementation را Phase-by-Phase انجام بده.

**منتظر تأیید من برای شروع Phase اول نمان.**

در صورت وجود ambiguity، ابتدا از Legacy behavior استفاده کن و فقط اگر واقعاً قابل استخراج نبود، آن مورد را مشخص کن.

هدف نهایی:

**یک Laravel 13 implementation کامل، تمیز، قابل نگهداری، امن و قابل Deploy روی Shared Hosting که از نظر Business Logic با Connectix-Bot فعلی کاملاً سازگار باشد.**
