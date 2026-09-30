# Production Deployment Guide

This is the operational half of Phase 19: how a build of this repository ends
up serving the bot on a shared host, and how to take it back if something
goes wrong. Everything here has been exercised by the first-run wizard
(`php artisan connectix:setup` or `/setup` over HTTP) or is a plain artisan
command; nothing requires a daemon, a queue worker or shell access beyond
cron.

## 1. What ships

The repository is a mixed tree on purpose:

* **Laravel** lives under `app/`, `bootstrap/`, `config/`, `database/`,
  `resources/`, `routes/`, `storage/`, `tests/` and `public/`.
* **Legacy** files (`bot.php`, `functions.php`, `index.php`, `config.php`,
  `setup/`, `debug/`, ...) stay in place until the cutover is signed off, so
  rollback is a webhook change rather than a restore.

Never deploy `.env`, `storage/` contents, `*.key` files, SQL dumps or
`config.php` from a machine that treats them as ordinary files: they are the
secrets. The root `.htaccess` denies them over HTTP for the mixed tree, and
`public/` does not contain them at all.

## 2. Two document-root shapes

**A. Pure Laravel (recommended for the new install).** Point the vhost at
`public/`. `public/.htaccess` routes everything through `public/index.php`;
no source tree, config or storage path is reachable over HTTP by construction.

**B. Mixed legacy/Laravel tree (during cutover).** The vhost keeps pointing at
the repository root, where the legacy `index.php` and root `.htaccess` live.
The root `.htaccess` is what stands between the internet and `.env`,
`config.php`, `debug/`, `app/`, `vendor/`, `storage/` and friends: it answers
403 for those prefixes and for `*.sql`, `*.log`, `*.key` style files. The
Laravel admin panel and webhook are served under `/admin`, `/setup` and
`/telegram/webhook` in both shapes; only shape B exposes `/` to legacy.

Keep `.htaccess` overrides enabled (`AllowOverride All` or the host's
equivalent). On hosts that disable `mod_rewrite`, the Laravel routes need an
`index.php` front-controller rewrite done by hand - `public/.htaccess` shows
the two rules.

## 3. Build

On a machine with PHP 8.3+ and Composer:

```bash
composer setup       # install, .env from example, key:generate, migrate, npm build
```

What each step is for:

* `composer install` - `vendor/` from `composer.lock`. Production must use
  `--no-dev`.
* `.env` copy - only if one does not exist yet.
* `php artisan key:generate` - writes `APP_KEY`.
* `php artisan migrate --force` - see §5.
* `npm install && npm run build` - Vite assets into `public/build/`. The
  panel degrades without them (unstyled), the bot does not need them at all.

**Hosts without a shell for Composer.** Build the same tree locally (or in
CI), then upload `vendor/`, `public/build/` and the rest of the code. Upload
`vendor/` *after* the code so a half-transferred tree never runs against a
stale autoloader, and re-upload `vendor/composer/autoload_*.php` last of all.
Do not upload `node_modules/`, `tests/`, `.git/` or the legacy `debug/`
directory.

## 4. First run: the wizard

Visit `/setup` (or `php artisan connectix:setup` from a shell). It writes
`.env` in steps - database credentials, panel token, bot token, webhook
secret, admin accounts, bot texts - tests every value before saving it, and
finishes by running migrations and registering the webhook. The state it
needs between clicks lives in `storage/app/connectix/`, so the wizard can be
re-entered after an interrupted run.

Afterwards, verify:

```bash
php artisan connectix:setup status   # same report the wizard's first step shows
php artisan about                    # PHP/Laravel version, drivers, LINKED storage
php artisan route:list               # /telegram/webhook and /admin present
```

## 5. Database and migrations

* Production is `DB_CONNECTION=mysql`. SQLite is for the test suite only.
* The wizard runs `php artisan migrate --force`. By hand:
  `php artisan migrate --force` - `--force` is mandatory outside local
  development, where Laravel otherwise refuses to migrate.
* Migrations are additive. Read `database/migrations/` before uploading a
  new release (§12 Rollback).
* Legacy data is copied once with `php artisan legacy:import` (credentials in
  `LEGACY_DB_*`, empty = unreachable) and audited with `legacy:verify`. Import
  deletes and updates nothing: every table is matched on its natural key
  (`chat_id`, `order_number`, ...), so an interrupted run re-run converges,
  and rows created in Laravel after the first import are never overwritten.
  `--dry-run` shows what a run would change before it writes anything.

## 6. Filesystem and permissions

The web user (often `nobody`/`www-data` on shared hosts) must be able to
**write**:

| Path | Why |
| --- | --- |
| `storage/` (whole tree) | logs, cache, sessions, views, broadcast state, wizard state |
| `bootstrap/cache/` | config/route/view caches, package discovery |
| `public/storage` | symlink target for the public disk |
| `.env` | written by the wizard (read+write once) |

Typical: `chmod -R u+rwX storage bootstrap/cache` plus whatever group the
host uses. Secrets stay `600`: `.env` and `storage/app/connectix/*.key` are
readable by the app only.

## 7. `storage:link`

`public/storage -> storage/app/public` is created by `php artisan storage:link`
(the config already declares the link; the app boots without it). It is not
part of the wizard: run the command once per deploy - it is idempotent, so a
second run only reports the link already exists. Only the public disk needs
it - broadcast media and the wizard state live in non-public `storage/app/`
paths and are never served over HTTP.

## 8. Cron: one line, every minute

```cron
* * * * * cd /path/to/connectix && php artisan schedule:run >> /dev/null 2>&1
```

That single entry runs the whole schedule (`routes/console.php`): wallet
sync 03:00, client sync 03:15, user profile refresh hourly at :20, update
ledger prune hourly. Every command takes its own `Cache::lock()` and
`withoutOverlapping()`, so a slow panel response cannot pile runs up. There
is no worker to supervise: `QUEUE_CONNECTION=sync` and the webhook answers
inline, which is what a shared host is good at.

## 9. Telegram webhook

```bash
php artisan telegram:webhook info     # what Telegram currently points at
php artisan telegram:webhook set      # register/update TELEGRAM_WEBHOOK_URL
php artisan telegram:webhook remove   # detach before a cutover or rollback
```

* `set` refuses to register without `TELEGRAM_WEBHOOK_SECRET`: the endpoint
  answers 403 to anything else, including updates forged by a stranger.
* `TELEGRAM_WEBHOOK_URL` must be the **public https URL** of
  `/telegram/webhook`. Telegram rejects plain http.
* Cutover between legacy and Laravel is exactly: `telegram:webhook remove`
  on the old, `set` on the new (or point `set` at the new host once). Keep
  `info` output of the working install before changing anything.
* `getWebhookInfo`'s `last_error_message` is the first place to look when
  users report silence.
* Hand-testing the endpoint with `curl` needs the **parsed** `.env` value:
  `TELEGRAM_WEBHOOK_SECRET` is stored quoted, and posting the raw line
  including the quotes fails verification with 403 `{"ok":false}` even when
  the registered secret is correct.

## 10. Cache and config optimization

After every deploy that touches config, routes or views:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Config cache is safe to ship: the wizard's `.env` writes go through
`EnvWriter::apply()`, which re-caches automatically. **The one rule for
tests**: a stale `bootstrap/cache/config.php` is what caused a full suite of
false failures during Phase 18 - always `php artisan config:clear` (or
`composer test`, which does it) before `php artisan test`.

## 11. Production logging

* `LOG_CHANNEL=daily`, `LOG_STACK=daily`, `LOG_LEVEL=info` are shipped in
  `.env.example`: a single `laravel.log` grows until the host quota kills
  the account; daily files rotate and Laravel prunes old ones.
* Everything that touches Telegram or the panel runs through
  `App\Support\LogRedaction`: bot tokens, panel tokens, passwords, bearer
  headers and long digit runs are masked, and messages are squished to one
  300-character line. `mask_bindings_in_exception_messages` is on for every
  connection, so a failed query never prints a client password.
* Webhook handling failures are logged with handler class, update id and
  chat id (`Telegram handler failed...`) - grep `storage/logs/laravel-*.log`
  for those when a user reports a dead button.

## 12. Backup strategy

Before every release, every migration and every import:

1. **Database**: the host's phpMyAdmin export or
   `mysqldump --single-transaction` of the app database. This is the only
   thing that cannot be rebuilt from the repository.
2. **`.env`**: the secrets - token, panel token, webhook secret, `APP_KEY`.
   Without `APP_KEY`, encrypted cookies and the remembered login are lost
   (a new one can be generated, at the cost of signing everyone out again).
3. **`storage/app/connectix/`**: wizard state and generated key files.
4. Nothing else is precious: code comes from git, `vendor/` from Composer,
   media (broadcast uploads, guides) is replaceable and worth a periodic
   extra copy if operators upload originals only there.

Suggested rhythm: dump the database weekly and before each release; keep
`.env` in the host's password manager, not in the repository (it never has
been tracked).

## 13. Rollback strategy

Code and configuration roll back independently:

```bash
# 1. Stop the new code from receiving updates
php artisan telegram:webhook remove

# 2. Move the code back (git or the uploaded archive)
git checkout <previous-tag>       # or re-upload the previous tree
composer install --no-dev         # if dependencies changed

# 3. Schema: migrations are additive - restore the backup taken in §12
#    only when the newer release introduced destructive changes.

# 4. Re-point Telegram at whichever install should answer
php artisan telegram:webhook set
```

Legacy as fallback needs no restore at all: during cutover the legacy tree
is still in place, so step 1 + pointing the webhook at the legacy URL is the
whole rollback. That is why Phase 20 keeps legacy code until the release is
signed off.

## 14. Release verification

Run in order; every line has to pass before the release is announced:

```bash
php artisan about                 # PHP >= 8.3, drivers as configured
php artisan route:list            # webhook + admin + setup routes
php artisan migrate --force --pretend   # what would run (dry run)
php artisan connectix:setup status
php artisan telegram:webhook info # URL matches this host, pending = 0
php artisan schedule:run          # once, by hand - exits 0
composer test                     # config:clear + full suite
git diff --check && git status    # clean tree
```

Then the human checks: `/start` through the real Telegram client answers
with the welcome message, the admin panel signs in, one wallet purchase and
one card receipt complete on production data.

## 15. Verified deployment record (2026-09-30)

The first production cutover pass against `https://lcl-laravel.mehdisite.ir`
ran every check in §14 plus the live probes below. Each line was observed,
not assumed:

* **Environment**: `APP_ENV=production`, `APP_DEBUG=false`,
  `APP_URL=https://...`. With debug off an unhandled exception renders the
  plain framework page (verified: the method-not-allowed page dropped from
  ~900 KB of stack trace to a 1 KB body).
* **Scheme and client ip**: the site is published through a Cloudflare
  tunnel whose `cloudflared` connects from loopback, so `bootstrap/app.php`
  trusts `127.0.0.1`/`::1` and `AppServiceProvider` forces https whenever
  `APP_URL` is an https URL. Before that, `/admin` redirected to `http://`,
  where the `Secure` login cookie is dropped and every rate limit shared one
  address. Verified after the change: `/admin` answers `302` to
  `https://.../admin/login`, and `http://` entry points are upgraded too.
* **Webhook**: `telegram:webhook info` shows this host, `pending_update_count`
  0; a hand-signed POST answers `200 {"ok":true}` both through Cloudflare and
  straight at the local origin; a forged admin `/start` produced a real
  delivered welcome message with no log entry.
* **`connectix:setup status`**: all checks `[OK]`, including live Telegram
  `getMe` (`@EchoSafedBot`) and a seller-panel round trip ("توکن فروشنده
  پذیرفته شد").
* **Migrations**: `migrate:status` all ran, `migrate --force --pretend`
  reports nothing to migrate.
* **Backups** (§12) live outside both document roots in
  `C:\xampp\backup-connectix\`: dated `mysqldump`, the `.env`, and
  `storage/app/connectix/`. Legacy code is held by tag `v3.3.6`.
* **Schedule**: the crontab entries exist (`schedule:list`), and on a host
  without cron the Windows task `ConnectixSchedule` runs
  `schedule:run` every 5 minutes (PHP `C:\Progra~1\php\php-8.5.8\php.exe`,
  XAMPP's own PHP is 8.2 and fails Composer's platform check). Output appends
  to `storage/framework/schedule-run.log`.
* **Hand-signed webhook tests** must post the *parsed* `.env` secret - see
  the quoting warning in §9.
