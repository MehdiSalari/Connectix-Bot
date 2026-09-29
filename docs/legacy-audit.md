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
| Broadcast without a session | `broadcast_progress.php` only required `config.php` | behind `admin.auth` + `admin.role:admin` (Phase 14) |
| Flood-control loss | `parameters.retry_after` ignored, recipient dropped | bounded wait and retry, then reported (Phase 14) |

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

## 12. The admin panel (Phase 13)

Every legacy page under the panel root was rebuilt on Blade behind the
`admin.auth` middleware: the root `index.php` dashboard, `users/index.php`,
`users/user.php`, `users/profile.php`, `users/search.php`,
`transactions/transactions.php`, `transactions/wallet_transactions.php`,
`transactions/sms_payments.php` and the guide/broadcast forms of the root
`index.php`.

### Where settings live now

* Legacy wrote branding and copy to `setup/bot_config.json` once, at install
  time, and every page read that file. The new `panel_settings` table
  (`setting_key` primary key, `setting_value`, `updated_at`) is the database
  port of that file: `PanelSettingsService::saveOverrides()` upserts a row per
  field the settings form submitted, and a blank field **deletes** its row so
  the seller panel value surfaces again - the "clear this field" behaviour the
  legacy installer implied by simply not writing the key.
* Precedence is unchanged from Phase 5: a set `.env` value wins (an explicit
  operator override), then a `panel_settings` row, then the seller panel
  payload, then the built-in default. `PanelSettingsService::overrides()` is
  guarded with a catch so an install that has not run the migration yet - or a
  unit test that never touches the database - degrades to "no overrides"
  instead of throwing.
* Toggles (`bot_active`, `test`, `force_channel_join`, `bank.bot_notice`) are
  read through `PanelSettingsService::flag()`, so flipping a checkbox on the
  settings page changes the behaviour of `TelegramGateway`, `KeyboardFactory`,
  `ChannelMembershipService` and `SmsPaymentService` on the next update without
  an `.env` edit. This is the one place where the rewrite is more dynamic than
  legacy, which only read the JSON file.

### Settings save

`AdminSettingsController::update` mirrors the root `index.php` POST in order:
validate, store the local overrides, mirror the branding up to the seller panel
with `update-bot` (reusing the current bot payload from
`getTelegramBotConfig()`, so the token and the notification switches are not
invented here), then re-register the Telegram webhook. A panel or webhook
failure is reported as a warning flash while the local save stands - the
seller's own copy is never lost to a remote outage.

Two deliberate differences from legacy:

* fields the form cleared are **not** pushed as empty strings to the panel, so
  a cleared field reverts to panel branding instead of blanking the panel too;
* the webhook is only re-registered when `telegram.webhook_url` and
  `telegram.webhook_secret` are configured, the same policy as the
  `telegram:webhook` command, instead of legacy's unconditional call.

### Orders, wallets and clients

* `transactions/transactions.php` listed the `payments` table with the same
  search columns, and approved a pending row by writing `is_paid = 1`. The
  panel reuses `PaymentService::search()`, `markPaid()` and `markRejected()`,
  so a decision taken in the panel and one taken from a Telegram callback are
  literally the same code path. A row that already has a decision is refused
  instead of silently rewritten.
* Wallet administration reuses `WalletService::create()/adjust()` with
  `DONE_BY_ADMIN` and the optional announcement, so the ledger row and the
  Telegram notice match the legacy profile page.
* Client details keep the legacy split: the local `clients` row for the list,
  and a live `GET /v1/seller/clients/show` behind the "details" button, so an
  install without a reachable panel still lists its accounts.

### Guides and broadcast

* Guide uploads keep the legacy rules: standard platforms
  (`use`, `android`, `ios`, `windows`, `mac`, `linux`), mp4 up to 10 MB or a
  URL in a `.txt`, the counterpart file deleted when the other is written, and
  custom entries under `custom/` with a sanitised title.
* Broadcast is a faithful port of `broadcast/broadcast_start.php` and
  `broadcast/broadcast_progress.php`: the job is persisted in
  `storage/app/broadcast/broadcast_start.json`, the sent counter in
  `broadcast_done.stamp` so a dropped connection resumes, the request holding
  the Server-Sent Events stream performs the sends throttled to Telegram-safe
  speed, per-recipient failures are reported and the loop continues, and the
  job plus its media are cleaned up when the stream ends. Test mode delivers
  only to the administrator's own chat id. No queue and no worker, by design.

### Role restrictions

List pages are open to `admin` and `editor`; every mutating action (wallet
create/adjust, order decision, settings save, guide add/delete, broadcast
start) requires `admin`. Legacy only checked that *some* session existed, so
this is the intended Phase 12/13 behaviour, not a parity break.

## 13. Broadcast system (Phase 14)

The fan-out was built with the panel in Phase 13; this phase closed the gaps
that were left against `broadcast_start.php` and `broadcast_progress.php`.

### Test send is immediate

* Legacy answered the "test" checkbox inside `broadcast_start.php`: it sent
  the message (or `تست موفق از پنل ادمین`) to the administrator's own chat,
  deleted the uploaded media and returned `{success, message, description}`
  as JSON. `AdminBroadcastController::start` now does exactly that through
  `BroadcastService::sendTest()` and never writes a job, so no progress
  stream is opened for a test.
* Legacy sent a test image by public HTTPS URL and every other media type as a
  local `CURLFile`. The rewrite uploads all types as multipart form data, so
  nothing has to be published under a web-reachable path.

### Rate limiting

* The pacing is the legacy `usleep(333000)` - about three messages per second -
  now `connectix_bot.broadcast.delay_us`.
* Telegram answers an over-rate send with a non-ok envelope carrying
  `parameters.retry_after`. Legacy ignored it and lost that recipient.
  `TelegramApiException` now carries `retryAfter()` and
  `BroadcastService::deliver()` waits up to
  `connectix_bot.broadcast.flood_retry_after_cap` seconds and retries the same
  recipient `connectix_bot.broadcast.flood_retries` times, so a large install
  gets through the flood limit instead of silently dropping users. The cap
  matters on shared hosting: one slow recipient must not park the request that
  is streaming progress for everyone else. Past the cap the failure is
  reported in the per-recipient log like any other error.
* The single fixed delay of legacy was the only throttle; there is no queue,
  worker or scheduler anywhere in this path.

### State and resume

* `broadcast_start.json` holds the job and `broadcast_done.stamp` the count of
  recipients already sent to, both under `storage/app/broadcast`. A run reads
  the counter and skips everything at or below it, so a browser refresh, a
  dropped connection or a PHP timeout resumes instead of re-sending - the same
  two-file design legacy used, moved out of the code directory.
* Both files and the uploaded media are deleted once the stream finishes.
* Recipient selection is the legacy one: every `users.chat_id` that is not
  empty. Test mode uses the administrator's chat id instead of the user table.

### Authorization

`broadcast_progress.php` only called `config.php`: it checked no session, so
any request that reached the file while a job was pending ran the fan-out.
Both routes are behind `admin.auth` and `admin.role:admin` now. This is a
security fix, not a parity break; it is listed in section 8 as well.

## 14. Sync and background work (Phase 15)

Legacy had no scheduler at all. `update/clients.php`, `update/users.php`,
`update/clients_update.php` and `functions.php::smsPayment()` only did their
work when a human opened a page, and the audit used to call that "nightly
reconciliation" - the word appears in the docs, not in the code. This phase
moves that work into commands and a cron-driven schedule, and adds the
idempotence legacy lacked.

### Client and user reconciliation

* `connectix:sync-clients` is the console form of `update/clients_update.php`:
  it paginates `/v1/seller`, reads every client with `/v1/seller/clients/show`
  and upserts the `users` and `clients` rows in one transaction per client.
  Legacy followed `next_page_url` until it was null; this stops on the first
  empty page, and also stops when a page repeats the previous one, because a
  panel that ignores `page` would otherwise loop forever on a 1-minute cron.
* Legacy wrote `test = 1` on every user it touched. That flag decides whether
  a person gets a free trial instead of a paid purchase, so on a live install
  running the legacy updater silently turned paying customers into test
  accounts. The flag is now local: a new row gets the default, an existing row
  keeps whatever it had.
* `connectix:sync-users` is the console form of `update/users.php`, which
  scraped `t.me` once per user with no pacing, no budget and no resume - a
  table of any size was throttled by t.me and then killed by
  `max_execution_time`, with no way to see how far it got. The command takes a
  per-run budget, paces itself, prints the last user id it reached for the next
  run, and skips a user whose profile could not be read instead of aborting.
* Both commands take a `Cache::lock()`, so two cron ticks cannot interleave two
  upserts on the same rows. `Cache::lock()` needs the `cache_locks` table,
  which the default `CACHE_STORE=database` already provides.

### No duplicate processing

* Telegram redelivers any update it could not confirm, and legacy had no way
  to notice: the whole handler chain ran again, including a second order write
  and a second confirmation message. `telegram_updates` is now a ledger keyed
  by `update_id`, claimed with `insertOrIgnore` before the gateway sees the
  update, so a redelivery is answered and dropped. Two simultaneous deliveries
  of one id still settle on a single handler run, because the check is the
  primary key rather than a read followed by a write.
* If the ledger cannot be written - an install that never ran the migration -
  the failure is logged and the update is processed anyway. Refusing updates
  would take the bot offline, and at-least-once is what legacy did.
* A bank SMS gateway that retried one POST used to store the same deposit
  twice, and `SmsPaymentService::claim()` deliberately refuses to choose between
  two deposits of one amount - so the duplicate silently switched auto-payment
  off for a customer who had transferred the right amount. Each delivery now
  carries a `fingerprint` of bank, amount and normalised message text, and a
  repeat inside the match window is dropped while the gateway still receives
  its usual 202. The index is not unique on purpose: only the match window
  decides, so a genuine second transfer of the same amount is still stored.
* `connectix:prune` does the cleanups legacy only ever ran as a side effect of
  a user action - expired unmatched bank deposits (kept the moment they are
  matched) and, new, the update ledger rows older than 48 hours.

### Shared hosting

* The schedule in `routes/console.php` is driven by one cron entry,
  `* * * * * cd /path/to/connectix && php artisan schedule:run`, and works
  unchanged under `schedule:work` on a proper server. Every task is
  `withoutOverlapping()` and takes its own lock.
* There is no queue worker, daemon or polling loop anywhere: purchases, renewals
  and deposits are all settled inside the request or the webhook that triggered
  them, exactly as before.
* `.env.example` was missing every key a fresh install actually needs -
  `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `CONNECTIX_PANEL_TOKEN` among
  them - so `composer run setup` produced an install whose webhook accepted
  forged updates and whose panel calls all failed. They are documented now, and
  `config/connectix_bot.php` no longer reads the avatar paths from a misspelled
  `CONNECTIX_BOT_BOT_AVATAR_*` pair that no `.env` could ever set.

## 15. Legacy data migration (Phase 16)

The rewrite keeps the legacy table and column names, so the common case - a
reseller who deploys the new code onto the database the old code already used -
needs no migration at all: `migrate` only adds what is new. `legacy:import`
covers the other case, a fresh Laravel database filled from an old install.

| Legacy `setup.php` data half | Laravel equivalent |
| --- | --- |
| `CREATE TABLE IF NOT EXISTS ...` for users, clients, payments, wallets, wallet_transactions | the schema migration, run by `migrate` |
| insert/update each panel client plus its user | `connectix:sync-clients` (Phase 15) |
| `telegram-wallets` import with `ON DUPLICATE KEY UPDATE` | `connectix:sync-wallets` |
| `INSERT IGNORE` into `wallet_transactions` | `connectix:sync-wallets` |
| a `SELECT` on every table afterwards | `legacy:verify` |

* `legacy:import` reads the old database through a dedicated read-only
  `legacy` connection (`LEGACY_DB_*` in `.env`). Nothing in the request path
  ever opens it, so leaving the keys empty keeps the old database unreachable.
  A SQLite export can be imported as easily as a live server.
* It only ever inserts. Every row is matched on its natural key (`admins.email`,
  `users.chat_id`, `wallets.chat_id`, `clients.id`, `payments.order_number`,
  and the primary key for the two ledgers), so a run that is interrupted and
  repeated converges instead of duplicating, and nothing is ever deleted or
  updated. Rollback is therefore the backup taken before the run, not an undo
  command.
* Writing requires `--confirm`; without it the command walks the same rows and
  only reports what it would do. `--only=users,clients` limits the run.
* A row that cannot be mapped - no natural key, unknown column, constraint
  violation - is counted, logged and skipped. It is never silently dropped.
* `legacy:verify` compares the counts table by table and fails when a legacy
  row is still missing locally, which is the check to run against a copy before
  the live bot is pointed at it.
* The panel half of the legacy installer is `connectix:sync-clients` plus the
  new `connectix:sync-wallets`, which is scheduled daily at 03:00. The wallet
  import keeps three legacy details: the balance is overwritten because the
  panel is the authority, a transaction without a `transaction_id` that is a
  decrease is recorded as a `BUY`, and the panel's Jalali timestamps are
  converted to Gregorian (`App\Support\JalaliCalendar`, the same arithmetic
  conversion legacy used) before they are stored. History is matched on the
  tuple that identifies a row - wallet, amount, operation, status, type and
  timestamp - so a repeated run does not duplicate it, because the panel does
  not expose an id for every transaction.

## 16. The first-run installer (Phase 18)

Legacy installed the bot from three files in the document root: `setup/index.php`
(the form), `setup/setup.php` (one long script that wrote a `config.php` next to
itself) and `setup/setup_progress.php` (the progress poll). Two of them wrote
credentials into a PHP file served by the same web server, and the script had no
steps to retry: one failure meant starting over, with no way to see how far it had
got.

The rewrite keeps the same sequence and makes each part its own step.

| Legacy step | Rewrite |
| --- | --- |
| the form's environment report | `GET /setup/requirements` |
| writing `config.php` | `App\Support\EnvWriter`, the only writer of `.env` |
| `CREATE TABLE IF NOT EXISTS` per table | `POST /setup/migrations` → `migrate --force` |
| seller panel login for the token | `POST /setup/connectix` → `ConnectixService::login()` |
| `getMe` on the bot token | `POST /setup/telegram` → `getMe()` |
| `setWebhook` with the shared secret | `POST /setup/webhook` |
| `setAdmin()` upsert | `POST /setup/admin` |
| writing `setup/bot_config.json` | `panel_settings` rows plus `CONNECTIX_BOT_*` in `.env` |
| the closing screen | `POST /setup/complete`, live checks, then `GET /setup/done` |
| `config.php` existing = installed | `App\Services\Setup\InstallationService` live checks |

* **State is derived, not remembered.** `isInstalled()` reads the environment, the
  database, the schema, the credentials and the admin table on every request.
  `storage/app/connectix/setup.json` records which step last ran, for the report
  and for `reset`, and is deliberately *not* the criterion: a deleted or forged
  state file cannot unlock an uninstalled runtime, and a lost one cannot lock an
  installed one out of its own panel.
* **The installer closes itself.** Once the checks pass, `/setup/*` answers 404
  to everyone except a signed in administrator with the `admin` role. The one
  exception is the closing report immediately after a successful install, allowed
  through the operator's own session because the application only became
  installed a moment earlier and they are not signed in to the panel yet.
* **Nothing destructive.** The schema step runs `migrate --force` and reports the
  tables it could not create. There is no `migrate:fresh`, no `db:wipe` and no
  `truncate` anywhere in the installer or its CLI, because the database it points
  at is very often a populated one. `reset` removes the state file and nothing
  else.
* **Secrets stay secret.** Every credential goes to `.env` and none of them is
  echoed back: the views show whether a value is set, never what it is. A failed
  step logs the exception class and a redacted message, with anything shaped like
  `password=…`, `token: …` or `bearer …` masked, because a shared host's log file
  is readable by more people than this application.
* **A release without `APP_KEY` can still install itself.** `EncryptCookies` and
  the session refuse to boot without a key, so the key is resolved in
  `AppServiceProvider::boot()` (`App\Services\Setup\ApplicationKey`) before the
  HTTP kernel: from `config`, then `.env`, then a `0600` cache file under
  `storage/`, and generated and persisted only if none of those had one. The key
  is stable across requests, or the wizard's own session would die after every
  click. The test suite never takes this path, so a test run cannot write to the
  developer's `.env`.
* **The same work from a shell.** `php artisan connectix:setup` offers
  `status`, `requirements`, `migrate`, `webhook`, `complete` and `reset`, for a
  host where the operator would rather not click through nine screens. `status`
  prints the same check report the first step shows.

## 17. Open items this audit could not settle

* The `payments` receipt column set. Receipts are forwarded to the
  administrators as Telegram photos, never stored (Phase 9), matching legacy,
  which sent the incoming `file_id` straight to each admin without saving it.
* Whether a percentage above 100 is reachable through the panel UI. The clamp
  is safe either way, but if the panel forbids it the clamp is inert.
* The real production schema. Only the dump and a local SQLite database have
  been verified; `migrate` against production MySQL is still untested.
* The panel half of the installer against a live seller panel: `login()`,
  `getTelegramBotConfig()` and the webhook calls are covered by fakes, and the
  token probe only proves the panel accepts the token it is given.
* The wizard against a real MySQL account. The tests run it on SQLite, and the
  `CREATE DATABASE IF NOT EXISTS` branch and the privilege failures it exists for
  have not been exercised.

## 18. Security hardening (Phase 17)

The audit's live findings (section 8) plus everything that surfaced while the
wizard landed, fixed and pinned by tests:

* **Log redaction has one home.** `App\Support\LogRedaction` masks the bot
  token inside `api.telegram.org/bot<id>:<secret>/method` URLs, bare
  `<id>:AA...` tokens, `password=`/`token:`/`api_key=` pairs, `Bearer <token>`
  (which has no `=` for the pair rule to find), and runs of twelve or more
  digits down to their last four - then squishes everything into a single line
  of at most 300 characters. `TelegramService`, `TelegramApiException`,
  `ConnectixApiException` and the wizard's failure log all go through it, and
  `config/database.php` sets `mask_bindings_in_exception_messages` on every
  connection so a failed query never renders a plaintext client password or
  seller token. With `APP_DEBUG=false` the PHP error handler is asked to drop
  argument lists too (`zend.exception_ignore_args`).
* **The webhook fails closed.** A blank `TELEGRAM_WEBHOOK_SECRET` is a
  misconfiguration, not an open door: `VerifyTelegramWebhook` answers 403
  instead of trusting anyone, because the installer always writes a random
  64-character secret before it registers the hook.
* **Sign-in is rate-limited and does not leak.** The login POST is throttled
  to five attempts per minute per email+IP, the unknown-address case pays the
  same single bcrypt check as the wrong-password case (`DECOY_HASH`), a
  rejection logs only the email and IP, and the remembered seller-token cookie
  is marked `Secure` whenever the request arrived over TLS.
* **Authorization gaps closed.** The client-details endpoint now hands the
  end user's password only to the `admin` role, `broadcast/progress` moved from
  `admin,editor` to `admin` (it is the loop that sends), and it additionally
  refuses any request whose `Sec-Fetch-Site` header is not `same-origin` or
  `none`. Every other route already sat behind `admin.auth` + `admin.role`.
* **Uploads cannot become code.** The Laravel broadcast accepts only
  jpg/png/gif/webp/mp4 by extension and sniffed type under 10MB, stored under a
  server-generated name. The legacy `broadcast_start.php` got the same shape
  with a wider document allow-list and a 50MB ceiling, rejects the file with a
  JSON error instead of silently dropping it, and `broadcast_progress.php`
  (the send loop itself) now requires the admin session it was missing.
* **Legacy XSS and session holes.** `users/profile.php` - which answered any
  visitor and embedded the seller panel token in its HTML - is behind the same
  `$_SESSION['admin_id']` gate as its siblings; its `userPic` parameter only
  survives as an `https://` URL; and every raw `name`/`avatar` echo in
  `users/profile.php`, `users/user.php` and `users/index.php` now goes through
  `htmlspecialchars` or `addslashes` (the avatar also sits inside a JS string).
* **The bank gateway can be told apart from a stranger.** When
  `BANK_SMS_SECRET` is configured, `POST /bank/sms` requires it in
  `X-Bank-Sms-Secret` (compared with `hash_equals`) and is rate-limited to 60
  a minute; unset, the legacy contract holds and no header is needed. The SMS
  text that reaches the log has its digit runs masked.
* **Response headers.** `SecurityHeaders` (outermost in the stack, so even the
  installer's redirects carry it) sends `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, a `Permissions-
  Policy`, a CSP of `default-src 'self'` with inline styles/scripts,
  `fonts.bunny.net`, image and `data:` allowances plus the Vite dev origin
  only outside production, and HSTS strictly on TLS requests.
* **Legacy HTTP lockdown.** Root `.htaccess` denies `.env*`, `config.php`,
  `*.sql`, `*.log`, archives and the tooling trees (`app/`, `vendor/`,
  `storage/`, `tests/`, ...) over HTTP; `debug/.htaccess` denies the directory
  outright (SQL dumps, a 50MB backup zip, probe scripts); `setup/.htaccess`
  denies `*.json`/`*.txt` so `bot_config.json` is filesystem-only while
  `setup_progress.php` stays reachable; `broadcast/.htaccess` denies the job
  state files; `broadcast/uploads/.htaccess` strips every script handler and
  refuses script-like names while the media itself stays downloadable for
  Telegram. Documented defaults moved to `.env.example` (`APP_ENV=production`,
  `APP_DEBUG=false`, commented `SESSION_SECURE_COOKIE`, `BANK_SMS_SECRET`) and
  `config.example.php` now carries unmistakable `<...-token>` placeholders.

Covered by `tests/Feature/SecurityHardeningTest` (headers, login throttle,
upload rejection, role and origin gates, client password, bank secret) and
`tests/Unit/LogRedactionTest`; the suite stands at 395 tests, 1242 assertions.

Left to the operator: rotating the bot token is only necessary if `config.php`
has ever left the machine - it has never been tracked by git.

## 19. Testing and legacy parity (Phase 18)

Phase 18 compared each flow branch by branch against `bot.php` /
`functions.php` and pinned the comparison with tests. The suite stands at 430
tests, 1381 assertions.

### The state clear belonged to three branches, not to every update

* **Legacy**: `userInfo()` - whose first statement was `actionStep('clear', ...)`
  (functions.php:194) - ran only at bot.php:104 (`/start`), bot.php:390
  (`main_menu`) and bot.php:400 (`new_menu`).
* **The bug**: `UserService::sync()` ported `userInfo()` wholesale and ran on
  *every* webhook update, so `users.action` was wiped before the handler read
  it. No multi-step flow survived its second update (`PurchaseHandler` died on
  the missing `acc` key, and the E2E walk produced no order at all).
* **Fixed**: the clear is gone from `sync()`; `StartHandler` and
  `MainMenuHandler` clear where legacy put it. Pinned by
  `TelegramGatewayTest::test_it_keeps_the_conversation_state_across_updates`,
  `...test_the_start_branch_clears_the_conversation_state`, and exercised
  through the real webhook by `EndToEndPurchaseTest`.

### `new_menu` still exists in old chats

Legacy keyboards carry the `new_menu` callback
(functions.php:2391/2425/2508/2983) and messages live forever, so
`MainMenuHandler` claims it as well: the state is cleared through
`userInfo()`'s old path and the welcome menu is posted as a fresh message
(bot.php:400), not as an edit.

### What the failure and edge tests pin

* **Trial accounts.** The `test` flag is committed the moment the panel
  creates the client, so a failed profile fetch still consumes the one free
  trial (legacy's autocommit), and a failed store reports only the error -
  no success message, no half-written row (legacy wrote a row with empty
  credentials; the rewrite does not).
* **Panel failures during acceptance.** An accept whose panel call fails
  leaves the order `Pending` and answers `رکورد مورد نظر یافت نشد.`; a
  renewal whose `add-plan` fails stays `Pending`; a failure to *deliver* the
  credentials still marks the order `Paid`, because the account exists.
* **Telegram outages.** A receipt whose forward fails still writes the order
  and clears the state - the money arrived, the silence must not.
* **Duplicate receipts.** A second photo after the state was cleared
  acknowledges without creating a second order; the E2E redelivery test
  asserts the same through the webhook ledger.
* **Coupon window.** The bounds are inclusive exactly as legacy compared
  them, and the default "now" is the Tehran ISO string legacy passed.
* **Admin cookies.** `remember_token`-style login: `HttpOnly`, `SameSite=Lax`
  (Symfony normalises case), 30 days, `Secure` when served over HTTPS - the
  secure flag is only reachable from an `https://` request because Symfony
  unsets `HTTPS` for http URIs.
* **Plan label fallback.** A panel answer without `plans` falls back to the
  ordered plan's parsed title instead of rendering a blank line
  (`ClientProvisioner::currentPlanName`; its last tier - the raw `plan_id` -
  is unreachable through provisioning, which requires a sellable plan first).
* **End to end.** `EndToEndPurchaseTest` drives `/start` -> buy -> group ->
  device count -> plan -> card -> receipt -> admin accept through
  `POST /telegram/webhook` with the configured secret, and expects a `Paid`
  order, a stored client and the caption legacy sent.

### Test infrastructure notes

* `Http::fake()` keeps the first registered pattern for a URL: re-faking a
  stub set up in `setUp()` never wins. Panel payloads are therefore swapped
  through properties the stub reads per request (`$storeFails`,
  `$panelClient`), or by rebuilding the whole factory via `Http::swap()` in
  `refake()`.

### Known divergences, deliberately kept

* **Profile refresh on every update.** Legacy re-scraped `t.me/<username>`
  only at `/start`, `main_menu` and `new_menu`; `UserService::sync()` still
  does it on every update for users with a username. The data ends up fresher
  and failures are logged and ignored, but it costs one outbound request per
  update. Moving it into the same three branches is a candidate follow-up if
  t.me load ever matters.
* **Wallet row on first contact.** `ensureWallet()` guarantees the zero
  balance wallet as soon as the user is seen, rather than wherever legacy
  happened to create it first.
