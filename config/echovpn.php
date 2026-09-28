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
    */

    'app_name' => env('ECHOVPN_APP_NAME', 'EchoVPN'),

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
    */

    'connectix' => [
        'base_url' => env('CONNECTIX_API_BASE_URL', 'https://api.connectix.vip'),
        'token' => env('CONNECTIX_PANEL_TOKEN'),
        'panel_url' => env('CONNECTIX_PANEL_URL', 'https://seller.connectix.vip'),
        'timeout' => (int) env('CONNECTIX_API_TIMEOUT', 30),
        'connect_timeout' => (int) env('CONNECTIX_API_CONNECT_TIMEOUT', 10),
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

    'active' => (bool) env('ECHOVPN_BOT_ACTIVE', true),
    'test_enabled' => (bool) env('ECHOVPN_TEST_ENABLED', true),
    'force_channel_join' => (bool) env('ECHOVPN_FORCE_CHANNEL_JOIN', false),

    /*
    |--------------------------------------------------------------------------
    | Telegram accounts
    |--------------------------------------------------------------------------
    */

    'admin_ids' => array_values(array_filter(
        array_map(
            static fn (?string $id): ?string => $id !== null ? trim($id) : null,
            explode(',', (string) env('ECHOVPN_ADMIN_IDS', ''))
        ),
        static fn (string $id): bool => $id !== ''
    )),

    'support_telegram' => env('ECHOVPN_SUPPORT_TELEGRAM'),
    'channel_telegram' => env('ECHOVPN_CHANNEL_TELEGRAM'),
    'telegram_channel_id' => env('ECHOVPN_TELEGRAM_CHANNEL_ID'),

    /*
    |--------------------------------------------------------------------------
    | Card-to-card payment
    |--------------------------------------------------------------------------
    */

    'card' => [
        'number' => env('ECHOVPN_CARD_NUMBER'),
        'name' => env('ECHOVPN_CARD_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet
    |--------------------------------------------------------------------------
    */

    'wallet' => [
        'minimum_deposit' => (int) env('ECHOVPN_WALLET_MIN_DEPOSIT', 10000),
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
        'name' => env('ECHOVPN_BANK_NAME'),
        'bot_notice' => (bool) env('ECHOVPN_BANK_BOT_NOTICE', true),
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
    | Display names for the seller plan groups. Keys match the group names
    | returned by the Connectix API and are used by PlanService::parseType().
    |
    */

    'plan_groups' => [
        'default' => 'ویژه',
        'Sublink' => 'ساب‌لینک',
        'Economic' => 'اقتصادی',
        'Iran Access' => 'ایران اکسس',
        'Business Class' => 'بیزینس کلاس',
        'BCSublink' => 'بیزینس ساب‌لینک',
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
    | Legacy `messages` object of setup/bot_config.json. Empty values fall back
    | to the hardcoded legacy defaults in MessageFactory.
    |
    */

    'messages' => [
        'welcome_text' => env('ECHOVPN_MESSAGE_WELCOME', ''),
        'contact_support' => env('ECHOVPN_MESSAGE_SUPPORT', ''),
        'questions_and_answers' => env('ECHOVPN_MESSAGE_FAQ', ''),
        'free_test_account_created' => env('ECHOVPN_MESSAGE_TEST_CREATED', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | External resources
    |--------------------------------------------------------------------------
    */

    'downloads' => [
        'source_url' => env('ECHOVPN_DOWNLOADS_URL', 'https://connectix.space/#download'),
        'cache_ttl' => (int) env('ECHOVPN_DOWNLOADS_CACHE_TTL', 3600),
        'timeout' => (int) env('ECHOVPN_DOWNLOADS_TIMEOUT', 20),
    ],

    'telegram_app_username' => env('ECHOVPN_TELEGRAM_APP_USERNAME', 'connectixapp'),

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
        'path' => env('ECHOVPN_GUIDES_PATH', 'assets/videos/guide'),
        'custom_path' => env('ECHOVPN_GUIDES_CUSTOM_PATH', 'assets/videos/guide/custom'),
        'bot_avatar_path' => env('ECHOVPN_BOT_AVATAR_PATH', 'assets/images/avatars/bot-avatar.jpg'),
        'bot_avatar_fallback' => env(
            'ECHOVPN_BOT_AVATAR_FALLBACK',
            'assets/images/avatars/bot-avatar-sample.jpg'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web app
    |--------------------------------------------------------------------------
    |
    | The Telegram WebApp entry point. When null the application URL is
    | derived from the incoming request, matching legacy behaviour where
    | bot.php was swapped for app.php in the current URL.
    |
    */

    'webapp_url' => env('ECHOVPN_WEBAPP_URL'),
];
