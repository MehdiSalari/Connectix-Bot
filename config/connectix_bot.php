<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Connectix VPN Bot configuration
|--------------------------------------------------------------------------
|
| Non-secret runtime settings. Every secret (bot token, panel token, webhook
| secret) lives in the .env file and is read through env() below.
|
| Values map 1:1 to the legacy `setup/bot_config.json` file so that behaviour
| is preserved during the Laravel rewrite.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Application identity
    |--------------------------------------------------------------------------
    |
    | Whitelabel branding. `app_name` is intentionally empty by default: the
    | real name is resolved from the seller panel at runtime by
    | PanelSettingsService, so the same build can be installed by any reseller
    | and show their own brand. Set a value here only to pin the name locally.
    |
    */

    'app_name' => env('CONNECTIX_BOT_APP_NAME', ''),

    /*
    |--------------------------------------------------------------------------
    | Telegram bot credentials
    |--------------------------------------------------------------------------
    */

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connectix seller panel API
    |--------------------------------------------------------------------------
    |
    | The legacy application talks to https://api.connectix.vip/v1/seller/*
    | using a static bearer token. Verification of the peer certificate is
    | enabled by default; it was disabled in legacy for no good reason.
    |
    | get_attempts: how many times an idempotent GET is sent before a
    | transport failure is given up on (one live order was lost to a single
    | 10s connect timeout). POSTs are never retried - a second clients/store
    | would create a second account.
    |
    */

    'connectix' => [
        'base_url' => env('CONNECTIX_API_BASE_URL', 'https://api.connectix.vip'),
        'token' => env('CONNECTIX_PANEL_TOKEN'),
        'panel_url' => env('CONNECTIX_PANEL_URL', 'https://seller.connectix.vip'),
        'timeout' => (int) env('CONNECTIX_API_TIMEOUT', 30),
        'connect_timeout' => (int) env('CONNECTIX_API_CONNECT_TIMEOUT', 10),
        'get_attempts' => (int) env('CONNECTIX_API_GET_ATTEMPTS', 3),
        'verify_tls' => (bool) env('CONNECTIX_VERIFY_TLS', true),
        'user_agent' => env(
            'CONNECTIX_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bot feature toggles
    |--------------------------------------------------------------------------
    */

    'active' => (bool) env('CONNECTIX_BOT_ACTIVE', true),
    'test_enabled' => (bool) env('CONNECTIX_BOT_TEST_ENABLED', true),
    'force_channel_join' => (bool) env('CONNECTIX_BOT_FORCE_CHANNEL_JOIN', false),

    /*
    |--------------------------------------------------------------------------
    | Telegram accounts
    |--------------------------------------------------------------------------
    |
    | `admin_ids` is an explicit local override. When it is empty,
    | PanelSettingsService falls back to the administrators configured in the
    | seller panel, mirroring legacy `setup.php` (admin_id, admin_id_2,
    | admin_id_3).
    |
    */

    'admin_ids' => array_values(array_filter(
        array_map(
            static fn (string $id): string => trim($id),
            explode(',', (string) env('CONNECTIX_BOT_ADMIN_IDS', ''))
        ),
        static fn (string $id): bool => $id !== ''
    )),

    'support_telegram' => env('CONNECTIX_BOT_SUPPORT_TELEGRAM'),
    'channel_telegram' => env('CONNECTIX_BOT_CHANNEL_TELEGRAM'),
    'telegram_channel_id' => env('CONNECTIX_BOT_TELEGRAM_CHANNEL_ID'),

    /*
    |--------------------------------------------------------------------------
    | Panel-provided branding
    |--------------------------------------------------------------------------
    |
    | The legacy installer called GET /v1/seller/telegram-bot once and wrote
    | the response to setup/bot_config.json, so a reseller's own app name,
    | support handles, channel and card details survived the installation and
    | the rest of the code read that static file. PanelSettingsService
    | reproduces that behaviour with a cache instead of a JSON file.
    |
    | Set `enabled` to false to pin every value to the local environment.
    |
    */

    'panel' => [
        'enabled' => (bool) env('CONNECTIX_BOT_PANEL_ENABLED', true),
        'cache_ttl' => (int) env('CONNECTIX_BOT_PANEL_CACHE_TTL', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Card-to-card payment
    |--------------------------------------------------------------------------
    */

    'card' => [
        'number' => env('CONNECTIX_BOT_CARD_NUMBER'),
        'name' => env('CONNECTIX_BOT_CARD_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet
    |--------------------------------------------------------------------------
    */

    'wallet' => [
        'minimum_deposit' => (int) env('CONNECTIX_BOT_WALLET_MIN_DEPOSIT', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bank SMS auto-payment
    |--------------------------------------------------------------------------
    |
    | `banks` mirrors the legacy `bank/banks.json` file. Each entry maps a bank
    | key to a PCRE pattern whose first capturing group extracts the amount.
    | The captured amount is in Rial and divided by 10 to obtain Toman.
    |
    */

    'bank' => [
        'name' => env('CONNECTIX_BOT_BANK_NAME'),
        'bot_notice' => (bool) env('CONNECTIX_BOT_BANK_BOT_NOTICE', true),

        // When set, the gateway must send it in the X-Bank-Sms-Secret header.
        // Legacy had no such check and an existing gateway will not grow one
        // by itself, so an empty value keeps the old contract.
        'secret' => (string) env('BANK_SMS_SECRET', ''),

        'banks' => [
            'blu' => [
                'title' => 'بلو بانک',
                'method' => '/(\d{1,3}(?:,\d{3})*)\s*ریال/',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plan group labels
    |--------------------------------------------------------------------------
    |
    | Display names for the seller plan groups, matching the
    | `plan_group_names` object the legacy installer wrote to
    | setup/bot_config.json. These are the exact strings the running legacy
    | instance renders, including their spelling.
    |
    | Note that these labels are deliberately NOT the same as the feature
    | tags produced by PlanService::parseExtras(); legacy uses a ZWNJ spelling
    | for the tags (ساب‌لینک) and the panel spelling for the label (سابلینک).
    | Do not "tidy" one to match the other, or purchase menus will change.
    |
    */

    'plan_groups' => [
        'default' => 'ویژه',
        'Sublink' => 'سابلینک',
        'Economic' => 'اقتصادی',
        'Iran Access' => 'ایران اکسس',
        'Business Class' => 'بیزینس کلس',
        'BCSublink' => 'بیزنیس سابلنک',
        'Static IP' => 'آی‌پی ثابت',
    ],

    /*
    |--------------------------------------------------------------------------
    | Plan group descriptions
    |--------------------------------------------------------------------------
    */

    'plan_group_descriptions' => [
        'default' => 'دریافت نام کاربری و رمز عبور جهت ورود به نرم افزار Connectix و استفاده از 4 پروتکل و بیش از 10 کشور برای اتصال.',
        'Sublink' => 'دریافت لینک سابسکریپشن جهت استفاده در نرم افزار هایی که از V2Ray پشتیبانی میکنند (مثل V2RayNG و V2Box)',
        'Economic' => 'سرویس اقتصادی با قیمت مناسب برای کاربرانی که به دنبال یک راه حل ارزان و کارآمد هستند.',
        'Iran Access' => 'سرویس دسترسی به آیپی ایران برای هموطنان ایرانی مقیم خارج کشور',
        'Static IP' => 'دریافت نام کاربری و رمز عبور جهت ورود به نرم افزار Connectix و استفاده از آیپی ثابت.',
        'Business Class' => 'سرویسی با کیفیت بالاتر و متصل در شرایط اینترنت ملی برای کاربران حرفه‌ای.',
        'BCSublink' => 'دریافت لینک سابسکریپشن سرویس بیزنس کلس جهت استفاده در نرم افزار هایی که از V2Ray پشتیبانی میکنند (مثل V2RayNG و V2Box)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Plan group emoji
    |--------------------------------------------------------------------------
    */

    'plan_group_emojis' => [
        'default' => '📱',
        'Sublink' => '🔗',
        'Economic' => '💰',
        'Static IP' => '📍',
        'Iran Access' => '🏠',
        'Business Class' => '💼',
        'BCSublink' => '💼',
    ],

    /*
    |--------------------------------------------------------------------------
    | Bot messages
    |--------------------------------------------------------------------------
    |
    | The four `telegramMessages` texts returned by GET
    | /v1/seller/telegram-bot, keyed as in legacy `setup/bot_config.json`.
    | Values here are local overrides: leave them empty to serve whatever the
    | seller panel provides, and MessageFactory falls back to the hardcoded
    | legacy defaults only when the panel has nothing to offer.
    |
    */

    'messages' => [
        'welcome_text' => env('CONNECTIX_BOT_MESSAGE_WELCOME', ''),
        'contact_support' => env('CONNECTIX_BOT_MESSAGE_SUPPORT', ''),
        'questions_and_answers' => env('CONNECTIX_BOT_MESSAGE_FAQ', ''),
        'free_test_account_created' => env('CONNECTIX_BOT_MESSAGE_TEST_CREATED', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | External resources
    |--------------------------------------------------------------------------
    */

    'downloads' => [
        'source_url' => env('CONNECTIX_BOT_DOWNLOADS_URL', 'https://connectix.space/#download'),
        'cache_ttl' => (int) env('CONNECTIX_BOT_DOWNLOADS_CACHE_TTL', 3600),
        'timeout' => (int) env('CONNECTIX_BOT_DOWNLOADS_TIMEOUT', 20),
    ],

    'telegram_app_username' => env('CONNECTIX_BOT_TELEGRAM_APP_USERNAME', 'connectixapp'),

    /*
    |--------------------------------------------------------------------------
    | Guide assets
    |--------------------------------------------------------------------------
    |
    | Legacy reads guides from assets/videos/guide. Path is relative to the
    | project root so the same files can be shared with the legacy tree.
    |
    */

    'guides' => [
        'path' => env('CONNECTIX_BOT_GUIDES_PATH', 'assets/videos/guide'),
        'custom_path' => env('CONNECTIX_BOT_GUIDES_CUSTOM_PATH', 'assets/videos/guide/custom'),
        'bot_avatar_path' => env('CONNECTIX_BOT_AVATAR_PATH', 'assets/images/avatars/bot-avatar.jpg'),
        'bot_avatar_fallback' => env(
            'CONNECTIX_BOT_AVATAR_FALLBACK',
            'assets/images/avatars/bot-avatar-sample.jpg'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web app
    |--------------------------------------------------------------------------
    |
    | The Telegram WebApp entry point. When empty the home keyboard omits the
    | panel/profile row: legacy derived the URL by swapping bot.php for
    | app.php in the current URL, and that legacy WebApp has no Laravel
    | equivalent to point at.
    */

    'webapp_url' => env('CONNECTIX_BOT_WEBAPP_URL'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast fan-out
    |--------------------------------------------------------------------------
    |
    | `delay_us` is the pause between recipient sends, mirroring the legacy
    | `usleep(333000)` that throttled the broadcast progress stream to roughly
    | three messages per second so Telegram never rejected the fan-out.
    |
    | `flood_retry_after_cap` bounds how long a single recipient may stall the
    | run when Telegram answers 429 Too Many Requests. Legacy ignored the
    | `parameters.retry_after` it was given and lost that recipient; the cap
    | keeps a shared host from parking one HTTP request for a minute, so past
    | the cap the recipient is reported as failed and the loop moves on.
    |
    | `flood_retries` is how often one recipient is retried after a flood
    | answer before it is given up on.
    |
    */

    'broadcast' => [
        'delay_us' => (int) env('CONNECTIX_BOT_BROADCAST_DELAY_US', 333000),
        'flood_retry_after_cap' => (int) env('CONNECTIX_BOT_BROADCAST_FLOOD_CAP', 10),
        'flood_retries' => (int) env('CONNECTIX_BOT_BROADCAST_FLOOD_RETRIES', 1),
    ],
];
