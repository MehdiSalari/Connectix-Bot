# Legacy Audit and Legacy → Laravel Mapping

Phase 1 deliverable. Everything below was read out of the legacy tree at
commit `529f2d4`; nothing is inferred from the Laravel side.

## 1. The legacy tree

| Path | Lines | Role |
| --- | --- | --- |
| `bot.php` | 588 | Telegram update entry point and callback dispatch |
| `functions.php` | 3745 | Every business rule: plans, purchase, wallet, payment, guides, admin queries |
| `index.php` | — | Admin panel (dashboard, users, clients, payments, wallet, broadcast) |
| `login.php` / `logout.php` | — | Admin session handling |
| `app.php` | — | Telegram WebApp served in place of `bot.php` |
| `setup/setup.php` | — | Installer: fetches the whitelabel config from the seller panel |
| `setup/bot_config.json` | — | The installer's static output, read by everything else |
| `bank/sms.php` | — | Bank SMS webhook and receipt matching |
| `bank/banks.json` | — | Per-bank amount regexes |
| `update` | — | Nightly client/state reconciliation |
| `broadcast` | — | Mass message sender |
| `debug/echovpn.sql` | — | Schema dump (never served, never committed) |
| `config.php` | — | Database credentials, panel token, bot token |

`functions.php` is a single 3745-line file holding the plan grammar, the
purchase state machine, the wallet ledger, coupon rules, the admin SQL and
the guide menus. It is the source of truth for parity.

## 2. Database

Seven tables, all reproduced in
`database/migrations/0001_01_01_000000_create_legacy_schema_tables.php`.

| Table | Model | Notes carried over |
| --- | --- | --- |
| `users` | `App\Models\User` | `telegram_id` and `chat_id` are both kept; the bot keys off `chat_id` |
| `admins` | `App\Models\Admin` | `role` is `admin` or `editor` |
| `clients` | `App\Models\Client` | Provisioned accounts, one per purchase |
| `payments` | `App\Models\Payment` | See the tri-state below |
| `wallets` | `App\Models\Wallet` | |
| `wallet_transactions` | `App\Models\WalletTransaction` | The wallet ledger |
| `sms_payments` | `App\Models\SmsPayment` | Bank SMS auto-match records |

### The `is_paid` tri-state

`payments.is_paid` is a **nullable** column and the pending state is the one
every receipt review flow depends on:

| Stored value | `PaymentStatus` | Meaning |
| --- | --- | --- |
| `NULL` | `Pending` | Awaiting an admin decision |
| `'0'` | `Rejected` | |
| `'1'` | `Paid` | |

Two consequences are load-bearing:

* `paycheck()` refused to change an order whose `is_paid` was not null, which
  is what made a second tap on the admin button harmless. A plain Eloquent
  enum cast cannot represent this: Laravel returns `null` for a null
  attribute rather than an enum, so `isPending()` would have been a method
  call on null. `Payment::isPaid()` is therefore an attribute that normalises
  reads to an enum and writes `Pending` back as a real `NULL`.
* Amounts are `VARCHAR`. Arithmetic is done in integers, and
  `Payment::priceAmount()` strips the thousands separators before use.

## 3. Service mapping

| Legacy function | Laravel service | State |
| --- | --- | --- |
| `tg()` | `TelegramService` + `TelegramGateway` | done |
| `getSellerPlans()`, `parsePlanTitle()`, `approximateDays()`, `parseType()` | `Plan\PlanService` | done |
| `createClient()`, `updateClient()`, `getClientData()`, `getSellerPlansPayload()` | `Connectix\ConnectixService` | done |
| `getUser()`, `user()` | `User\UserService` | done |
| `actionStep()` | `User\UserStateService` | done |
| `wallet()`, `createWalletTransaction()`, `getWalletTransactions()` | `Wallet\WalletService` | done |
| `savePayment()`, `paycheck()` status half, payment search | `Payment\PaymentService` | done |
| `checkCoupon()`, `discount()` | `Coupon\CouponService` | done |
| `getGuideLink()`, `getCustomGuideItems()`, `guideButton()` | `Guide\GuideService` | done |
| `getDownloadLinks()` | `Download\DownloadLinkService` | done |
| `message()`, `tg_keyboard()` | `Telegram\MessageFactory`, `Telegram\KeyboardFactory` | done |
| `setup.php` panel fetch | `Panel\PanelSettingsService` | done |
| `smsPayment()` | — | Phase 9 |
| `paycheck()` provisioning half | — | Phase 9 |
| `guide()` | `Guide\GuideService` (data) + `GuideHandler` | done |

`functions.php` is deliberately **not** ported as one service. It stays in
place, untouched, until Phase 20.

## 4. The purchase state machine

`actionStep` stores one JSON blob per chat in the user's `data` column. The
flow is a flat `switch` on `action`, and each branch writes the next blob:

```
new_menu → group → device → plan → [discount] → pay_card | pay_wallet
   → (receipt photo) → pay / pay_wallet
```

* `group` — plan group, labels from `plan_group_names`
* `device` — device count, derived from the plans in the group
* `plan` — the chosen plan id
* `discount` — optional; `set` asks for a code, `apply` recalculates
* `pay_card` — card to card, `is_paid` stays `NULL` until an admin accepts
* `pay_wallet` — debited immediately, `is_paid` is `1` from the start

`UserStateService` mirrors this. Two behaviours are easy to lose:

* The `price` carried between steps keeps its thousands separators
  (`"120,000"`), and the coupon maths strips them before subtracting.
* `tryClear()` only resets the blob when the step is genuinely finished;
  clearing it on every update would drop a half-finished purchase.

## 5. Coupon rules

`bot.php` walked a fixed ladder, and each rung has its own message:

1. unknown code → `🚫 کد تخفیف وارد شده صحیح نمی باشد!🚫`
2. `is_active` not true → `🚫 کد تخفیف وارد شده غیرفعال است!🚫`
3. outside `start_date_text` / `end_date_text` → `🚫 کد تخفیف وارد شده منقضی شده یا هنوز فعال نشده است!🚫`
4. plan not in `plans_ids` and not `is_applied_to_all_plans` → `🚫 کد تخفیف وارد شده برای پلن انتخابی شما نمیباشد!🚫`

Details that are easy to get wrong and are now pinned by tests:

* `is_active` was compared with `== true`, so the panel's `"1"` counts as
  active.
* The date bounds were compared **lexically** against a Tehran clock
  formatted as `Y-m-d\TH:i:s.u\Z` — the `Z` on a Tehran time is a legacy
  mislabel, but keeping the exact string is what makes the comparison agree.
* Only a literal `null` bound is skipped. An empty string is compared, and
  therefore means "expired".
* `per_cent` wins over `amount`; a percentage above 100 was not clamped in
  legacy and produced a negative price. `CouponService` clamps at zero and
  logs it.
* A coupon with neither value made legacy fall through to a false
  `if ($discountResult)` and leave the user with no reply at all.

## 6. Order numbers

`CX{yy}{mm}{dd}{NN}`, where `NN` is the count of that day's orders plus one,
left padded to two digits and **not** truncated past 99. Legacy counted with a
`LIKE 'CX260929%'` query after the insert and then updated the row, with no
lock in between. `PaymentService::nextOrderNumber()` keeps the same numbers
and adds `lockForUpdate()` inside a transaction so two orders in the same
second cannot collide.

## 7. Download links

`getDownloadLinks()` scraped `connectix.space/#download` with
`file_get_contents()` and DOMXPath. The platform filter is a `match` on
**lower case** keys that compares against **capitalised** scraped names
(`'android'` → `'Android'`, `'ios'` → `'iOS'`). Any other argument hit the
`default` arm and returned the **unfiltered** list. That last part looks like
a bug but callers rely on it, so `DownloadLinkService` reproduces it.

## 8. Security findings carried into the rewrite

Found while porting, fixed where the port touched them:

| Finding | Legacy | Now |
| --- | --- | --- |
| Guide path traversal | `"assets/videos/guide/$action.txt"` built from callback data | `GuideService` allows `[A-Za-z0-9_-]+` only, then confirms the resolved path is inside the guide directory |
| Unbounded fetch | `file_get_contents()` with no timeout, no user agent, no status check | `Http` client with a timeout, a user agent, TLS on, and a 200-only parse |
| Scraper SSRF | no guard | loopback, private ranges and non-http schemes are refused |
| Coupon panel outage | `array_filter(null)`, fatal in PHP 8 | `rejectionReason()` returns a distinct "cannot verify" message instead of blaming the user's code |
| Receipt spam | unvalidated | Phase 9 |
| Bank SMS replay | duplicate check only | Phase 15 |

## 9. Branding

`setup/bot_config.json` and `debug/echovpn.sql` are the only remaining files
carrying the original product name. They are kept on purpose: the JSON is the
parity reference for the panel payload, and the SQL is the schema reference.
Laravel-owned code reads its name from `PanelSettingsService` instead, with
`Connectix Bot` as the fallback.

## 10. Trial account and guides (Phase 11)

### Free trial

* `keyboard('get_test')` rendered one button per free plan. Plans of the same
  group carry identical labels and the same `getTest_<group>` callback, so the
  handler (`FreeTestHandler`) drops the duplicate rows; the visible menu is
  unchanged.
* `getTest()` plan selection matched a **lower-case** `economic` and an exact
  `default` token against case-sensitive `strpos` titles: `economic` wants
  `+ Economic`/`+Economic`, `default` wants the first `Free` title with neither
  `Economic` nor `Sublink`. The token comes straight from the callback, so the
  capitalised `getTest_Economic` button answers
  `پلن مناسب برای نوع درخواستی (Economic) یافت نشد.` in legacy; that quirk is
  kept.
* The `test` flag is only true once the panel round-trip finished; the guard
  message is `⚠️ شما قبلا درخواست تست داده اید!`.
* The success text (`free_test_account_created`, default
  `اکانت تست شما با موفقیت ایجاد شد.`) resolves through `PanelSettingsService`:
  local override, then panel, then the built-in default.
* The confirm message carries the credentials inline
  (`👤 نام کاربری` / `🔑 رمز عبور` / optional `🔗 لینک سابسکریبشن`) and the
  `accounts` / `main_menu` keyboard.

### Guides

* `GuideHandler` opens the menu as a fresh `sendMessage` and deletes the source
  message (the `guide` branch of bot.php); every other guide callback edits the
  source message like the default `callBackCheck` branch.
* `use` → link button when a link file exists, otherwise the stored video;
  `install` → the platform picker; `custom_<n>` → the item's video.
* A missing or unreadable video answers the alert
  `🙅🏻 فعلا ویدیو آموزشی در دسترس نمی باشد!` and nothing else.
* Videos are uploaded as a multipart `sendVideo` with a dedicated 10s connect
  timeout and 120s body timeout; the button message is deleted afterwards (a
  failure to delete is only logged).

### Downloads

* `DownloadHandler` renders `دانلود اپلیکیشن Connectix برای <b>{label}</b>`,
  each download row is `📥 | {label}` with the URL from
  `DownloadLinkService`, and the Telegram channel button (`📲 | دانلود از تلگرام`)
  appears only for android (4), windows (5) and mac (11), built from config
  `connectix_bot.telegram_app_username`. Home and back rows close the page.

## 11. Admin authentication and authorization (Phase 12)

The `admins` table and its `Admin` model predated this phase. The web session
login behind `login.php`, the remembered `token` cookie of `logout.php` and the
`isset($_SESSION['admin_id'])` page guard were ported to `/admin`.

### Sign in

* `AdminLoginController::login` mirrors `login.php`: lookup `admins.email`,
  `Hash::check` against the bcrypt `password`, then open the `admin` guard
  session. Both an unknown email and a wrong password answer the single legacy
  message `Invalid email or password` (no user enumeration).
* The form is CSRF-protected and validated (`email` required/max 190,
  `password` required); a failed attempt is redirected back to the login page
  with the error flashed and the typed email kept as `old()` input.
* On success the response remembers the seller token in a 30-day
  `token` cookie (the legacy `setcookie('token', $admin['token'], ...)`).

### Remembered token auto-login

* With a `token` cookie, `login.php` called
  `https://api.connectix.vip/v1/seller/seller-data` with
  `Authorization: Bearer <token>` and only trusted the session when the seller
  `id` came back. `AdminLoginController::show` reproduces that via
  `ConnectixService::verifySellerToken`, which:
  * makes a GET with the cookie token overriding the config bearer,
  * returns `false` when the payload has no seller `id`,
  * treats a `ConnectixApiException` (non-2xx/network) as "panel no longer
    accepts it".
* A token that names neither an `admins` row nor a seller the panel still
  accepts is dropped (`Cookie::forget` → expired) and the admin stays on the
  login page. Legacy redirected there to `setup/index.php`; the installer was
  not ported, so the site keeps serving until credentials are entered.

### Roles and authorization

* `EnsureAdminRole` gate reads `admins.role` (`admin`/`editor`). `editor` may
  open the dashboard; routes that mutate data will be admin-only. Legacy
  stored but never enforced the role at runtime — this is the first place the
  enum has teeth, so it is a deliberate improvement, not a regression.
* `AuthenticateAdmin` replaces the `isset($_SESSION['admin_id'])` cheek of each
  legacy page: guests are sent to `admin.login` (preserving the intended URL
  for `redirect()->intended()` after signing in), and JSON requests get a
  401.
* `logout` invalidates the session, regenerates the CSRF token and expires the
  `token` cookie, matching `logout.php`.

## 12. Open items this audit could not settle

* The `payments` receipt column set. Receipts are forwarded to the
  administrators as Telegram photos, never stored (Phase 9), matching legacy,
  which sent the incoming `file_id` straight to each admin without saving it.
* Whether a percentage above 100 is reachable through the panel UI. The clamp
  is safe either way, but if the panel forbids it the clamp is inert.
* The real production schema. Only the dump and a local SQLite database have
  been verified; `migrate` against production MySQL is Phase 16.
