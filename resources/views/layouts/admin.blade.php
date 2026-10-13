<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="dark" data-accent="violet">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark light">
    <title>{{ $appName ?? 'پنل مدیریت' }}{{ isset($title) && $title !== '' ? ' | '.$title : '' }}</title>

    {{-- The icon is declared explicitly rather than left to the /favicon.ico
         probe: the .ico in public/ was a zero byte file, so the tab showed
         whatever the browser fell back to. The SVG scales to the tab strip,
         the PNG covers browsers that ignore it. --}}
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon-32.png') }}" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('favicon-32.png') }}">

    {{-- Theme is resolved before the first paint so the panel never flashes. --}}
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('cx-theme');
                var theme = stored || (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
                document.documentElement.dataset.theme = theme;
                document.documentElement.dataset.accent = localStorage.getItem('cx-accent') || 'violet';
            } catch (e) {
                document.documentElement.dataset.theme = 'dark';
            }
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/connectix.css') }}?v={{ \App\Support\AssetVersion::css() }}">
</head>
<body>
    @php
        $admin = auth('admin')->user();

        // Icon + label per destination. `$active` is supplied by each view and
        // still names the same keys as before; rendering walks a grouped
        // structure over this flat map instead, Airoxnet style.
        //
        // Entries whose route has not been registered yet are listed too, but
        // every render goes through $has() (Route::has), so a row only paints
        // once the wave that owns it has landed its routes. `$navGroups` below
        // is the four Airoxnet groups the fork's layout.php prints.
        $has = fn (string $name): bool => Route::has($name);

        $nav = [
            'dashboard' => ['route' => 'admin.dashboard', 'label' => 'داشبورد', 'icon' => '<path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z"/>'],
            'users'     => ['route' => 'admin.users.index', 'label' => 'کاربران', 'icon' => '<path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-6 1.8-6 4v2h12v-2c0-2.2-2.7-4-6-4Zm8-1a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm.5 3c-.6 0-1.2.1-1.7.3 1.1 1 1.7 2.2 1.7 3.7v2h4v-2c0-2.2-2.2-4-4-4Z"/>'],
            'orders'    => ['route' => 'admin.orders.index', 'label' => 'سفارش‌ها', 'icon' => '<path d="M6 2h12a1 1 0 0 1 1 1v18l-3-2-2 2-2-2-2 2-2-2-3 2V3a1 1 0 0 1 1-1Zm2 5v2h8V7H8Zm0 4v2h8v-2H8Zm0 4v2h5v-2H8Z"/>'],
            'wallet'    => ['route' => 'admin.wallet-transactions.index', 'label' => 'تراکنش‌های کیف پول', 'icon' => '<path d="M3 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v1h1a1 1 0 0 1 1 1v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6Zm2 0v12h14V9h-4a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h4V7H5Zm11 6.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/>'],
            'sms'       => ['route' => 'admin.sms-payments.index', 'label' => 'پیامک‌های بانکی', 'icon' => '<path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2Zm3 4v2h10V8H7Zm0 4v2h7v-2H7Z"/>'],
            'settings'  => ['route' => 'admin.settings.show', 'label' => 'تنظیمات ربات', 'icon' => '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm9 4a7.5 7.5 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7.6 7.6 0 0 0-2-1.2L16.1 3H11.9l-.4 2.6c-.7.3-1.4.7-2 1.2l-2.4-1-2 3.4 2 1.6a7.7 7.7 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1c.6.5 1.3.9 2 1.2l.4 2.6h4.2l.4-2.6c.7-.3 1.4-.7 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2Z"/>'],
            'guides'    => ['route' => 'admin.guides.index', 'label' => 'آموزش‌ها', 'icon' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-2 14.5v-9l7 4.5-7 4.5Z"/>'],
            'broadcast' => ['route' => 'admin.broadcast.show', 'label' => 'پیام همگانی', 'icon' => '<path d="M18 8a3 3 0 0 1 0 6v3a1 1 0 0 1-1.5.9L11 15H7a3 3 0 0 1 0-6h4l5.5-3.9A1 1 0 0 1 18 6v2Zm-9 4a1 1 0 1 0 0 2 1 1 0 0 0 0-2ZM5 9a1 1 0 0 1 1 1v4a1 1 0 1 1-2 0v-4a1 1 0 0 1 1-1Z"/>'],
            'appearance' => ['route' => 'admin.appearance', 'label' => 'ظاهر و تم', 'icon' => '<path d="M12 2a10 10 0 1 0 0 20c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.2 0-1.1.9-2 2-2h2.4c3 0 5.6-2.4 5.6-5.5C23 5.9 18.1 2 12 2ZM6.5 12a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm3-4a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm5 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm3 4a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/>'],
            'resellers' => ['route' => 'admin.resellers.index', 'label' => 'نمایندگی‌ها', 'icon' => '<path d="M12 2 9.1 8.6 2 9.2l5.4 4.7L5.8 21 12 17.3 18.2 21l-1.6-7.1L22 9.2l-7.1-.6L12 2Z"/>'],
            'tickets'   => ['route' => 'admin.tickets.index', 'label' => 'تیکت‌ها', 'icon' => '<path d="M12 2a9 9 0 1 0 0 18h1.8l3.9 2.5c.9.6 2.1-.1 2.1-1.1v-4.7A9 9 0 0 0 12 2Zm-4 8a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm4 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm4 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/>'],
            'admins'    => ['route' => 'admin.admins.index', 'label' => 'ادمین‌ها', 'icon' => '<path d="M12 1 3 5v6c0 5.6 3.8 10.7 9 12 5.2-1.3 9-6.4 9-12V5l-9-4Zm0 6a3 3 0 1 1 0 6 3 3 0 0 1 0-6Zm0 12.5c-2 0-3.8-.9-5-2.3.1-1.7 3-2.7 5-2.7s4.9 1 5 2.7a6.5 6.5 0 0 1-5 2.3Z"/>'],
            'gateways'  => ['route' => 'admin.gateways.index', 'label' => 'درگاه‌های پرداخت', 'icon' => '<path d="M2 6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v2H2V6Zm0 5h20v7a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-7Zm3 3a1 1 0 0 0 0 2h4a1 1 0 1 0 0-2H5Z"/>'],
            'panels'    => ['route' => 'admin.panels.index', 'label' => 'پنل‌های VPN و پلن‌ها', 'icon' => '<path d="M4 3h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 10h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2Zm2-8a1 1 0 1 0 0 2 1 1 0 0 0 0-2Zm0 10a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z"/>'],
            'crypto'    => ['route' => 'admin.crypto.index', 'label' => 'پرداخت کریپتو', 'icon' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15.9V19h-2v-1.1a3.9 3.9 0 0 1-3-3.4h2c.1.9 1 1.5 2.1 1.5 1.2 0 1.9-.6 1.9-1.3 0-.6-.4-1-2-1.4-2-.5-3.6-1.2-3.6-3.2 0-1.5 1.1-2.7 2.6-3.1V6h2v1.1c1.5.4 2.6 1.5 2.7 3.1h-2c-.1-.8-.8-1.4-1.8-1.4s-1.7.5-1.7 1.2c0 .7.6 1 2.2 1.4 2 .5 3.5 1.2 3.5 3.2 0 1.7-1.2 2.9-2.9 3.3Z"/>'],
            'coupons'   => ['route' => 'admin.coupons.index', 'label' => 'کد تخفیف', 'icon' => '<path d="M2 7a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v2a2 2 0 0 0 0 6v2a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-6V7Zm10.5 3h3v2h-3v-2Zm0 4h3v2h-3v-2Z"/>'],
            'custom-plan' => ['route' => 'admin.custom-plan.index', 'label' => 'پلن سفارشی', 'icon' => '<path d="M21 3H3a1 1 0 0 0-1 1v5h20V4a1 1 0 0 0-1-1ZM2 11v9a1 1 0 0 0 1 1h7V11H2Zm12 10h7a1 1 0 0 0 1-1v-9h-8v10Z"/>'],
            'flash-sales' => ['route' => 'admin.flash-sales.index', 'label' => 'حراج زمان‌دار', 'icon' => '<path d="M13 2 3 14h7l-1 8 11-13h-7l1-7Z"/>'],
            'loyalty'   => ['route' => 'admin.loyalty.index', 'label' => 'سیستم امتیازدهی', 'icon' => '<path d="m12 17.3-6.2 3.7 1.7-7L2 9.2l7.1-.6L12 2l2.9 6.6 7.1.6-5.5 4.8 1.7 7L12 17.3Z"/>'],
            'cards'     => ['route' => 'admin.cards.index', 'label' => 'کارت‌های مشکوک', 'icon' => '<path d="M12 2a5 5 0 1 0 0 10 5 5 0 0 0 0-10Zm0 12c-4.4 0-8 1.8-8 4v4h16v-4c0-2.2-3.6-4-8-4Z"/>'],
            'buttons'   => ['route' => 'admin.buttons.index', 'label' => 'دکمه‌های صفحه اصلی', 'icon' => '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm3 6h10v2H7v-2Zm0 4h6v2H7v-2Z"/>'],
            'emojis'    => ['route' => 'admin.emojis.index', 'label' => 'ایموجی‌های پرمیوم', 'icon' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20ZM8.5 11a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm7 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3ZM12 17.5c-2 0-3.8-1.1-4.7-2.8l1.7-.9c.6 1.1 1.7 1.7 3 1.7s2.4-.6 3-1.7l1.7.9c-.9 1.7-2.7 2.8-4.7 2.8Z"/>'],
            'winback'   => ['route' => 'admin.winback.index', 'label' => 'بازگشت مشتری', 'icon' => '<path d="M12 21s-7.5-4.9-9.5-9C1 8.5 3 5 6.5 5c2 0 3.6 1 5.5 3 1.9-2 3.5-3 5.5-3C21 5 23 8.5 21.5 12c-2 4.1-9.5 9-9.5 9Z"/>'],
            'notifications' => ['route' => 'admin.notifications.index', 'label' => 'یادآور و هشدار', 'icon' => '<path d="M12 22a2.5 2.5 0 0 0 2.5-2.5h-5A2.5 2.5 0 0 0 12 22Zm7-6v-5c0-3.1-1.6-5.6-4.5-6.3V4a2.5 2.5 0 0 0-5 0v.7C6.6 5.4 5 7.9 5 11v5l-2 2v1h18v-1l-2-2Z"/>'],
            'features'  => ['route' => 'admin.features.index', 'label' => 'ویژگی‌های ویژه', 'icon' => '<path d="m7.5 5.6 3.5 8.4 1.4-3.4 3.4-1.4-8.3-3.6Zm-2.7-.7 2 4.9-4.9-2 2.9-2.9Zm14.4 14.2-2-4.9 4.9 2-2.9 2.9ZM3.4 3.4l4.9 2-2 4.9-2.9-6.9Zm17.2 17.2-4.9-2 2-4.9 2.9 6.9Z"/>'],
            'account'   => ['route' => 'admin.account.show', 'label' => 'ایمیل و رمز عبور', 'icon' => '<path d="M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5Zm-3 5a3 3 0 1 1 6 0v3H9V6Zm3 8a1.5 1.5 0 0 1 .5 2.9V19a.5.5 0 0 1-1 0v-2.1A1.5 1.5 0 0 1 12 14Z"/>'],
            'security'  => ['route' => 'admin.security.show', 'label' => 'امنیت حساب من', 'icon' => '<path d="M12 1 3 5v6c0 5.6 3.8 10.7 9 12 5.2-1.3 9-6.4 9-12V5l-9-4Zm0 5a3 3 0 0 1 3 3v1h.5a1.5 1.5 0 0 1 1.5 1.5v4A1.5 1.5 0 0 1 15.5 17h-7A1.5 1.5 0 0 1 7 15.5v-4A1.5 1.5 0 0 1 8.5 10H9v-1a3 3 0 0 1 3-3Zm0 2a1 1 0 0 0-1 1v1h2v-1a1 1 0 0 0-1-1Z"/>'],
            'activity'  => ['route' => 'admin.activity.index', 'label' => 'فعالیت‌ها', 'icon' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 10.4 4 2.3-.9 1.5L11 13.5V6h2v6.4Z"/>'],
        ];
        $active = $active ?? '';

        // چهار گروه Airoxnet، همان‌طور که layout.php فورک چاپ می‌کند: عمومی،
        // فروشگاه، ربات، حساب. سرگروه فقط وقتی رندر می‌شود که لااقل یکی از
        // لینک‌هایش موجود باشد؛ لینک‌هایِ بدون مسیرِ ثبت‌شده رد می‌شوند.
        $navGroups = [
            'عمومی'    => ['dashboard', 'users', 'orders', 'wallet', 'sms', 'resellers', 'tickets', 'admins'],
            'فروشگاه'  => ['gateways', 'panels', 'crypto', 'coupons', 'custom-plan', 'flash-sales', 'loyalty', 'cards'],
            'ربات'     => ['settings', 'guides', 'appearance', 'broadcast', 'buttons', 'emojis', 'winback', 'notifications', 'features'],
            'حساب'     => ['account', 'security', 'activity'],
        ];

        // A Tehran timestamp for the clock to start from: the JS ticks the
        // seconds forward from this epoch instead of reading the visitor's
        // own clock, whose offset the panel cannot trust. Greeting and date
        // are server rendered, so the header reads correctly even with JS off.
        $tehranNow = now('Asia/Tehran');
        $tehranHour = (int) $tehranNow->format('G');
        $jalali = $jalaliToday ?? \App\Support\JalaliDate::today();
        $jalaliDate = \App\Support\JalaliDate::toPersianDigits(
            sprintf('%04d/%02d/%02d', $jalali['year'], $jalali['month'], $jalali['day'])
        );

        // شمارندهٔ تیکت‌های باز، فقط وقتی مدل تیکت وجود دارد. یک کوئری سبک
        // است، ولی هر پنلی نباید به خاطر یک شماره به دیتابیس ضربه بزند: خطا
        // (جدولی که هنوز مهاجرت نشده، دیتابیسِ در دسترس نیست) یعنی صفر، و
        // بدون خطا هم صفر یعنی بدون نشان.
        $openTickets = 0;
        if (class_exists(\App\Models\Ticket::class) && $has('admin.tickets.index')) {
            try {
                $openTickets = \App\Models\Ticket::query()->where('status', 'OPEN')->count();
            } catch (\Throwable) {
                $openTickets = 0;
            }
        }

        // کانال تلگرام (کارت «کانال تلگرام» زیر کارت نسخه): فقط وقتی پیکربندی شده.
        $channelTelegram = trim((string) (\App\Services\Panel\PanelSettingsService::class
            ? app(\App\Services\Panel\PanelSettingsService::class)->channelTelegram()
            : ''));

        // pill وضعیت: همان flag ربات که بالای صفحه می‌آید، AND با سلامتِ
        // خودِ دیتابیس — همان رفتار panelDbOk فورک. اگر پرس‌وجو خطا بخورد
        // (نصب ناقص، جدول غایب) به‌جای ریختن صفحه، غیرفعال نشان داده می‌شود.
        try {
            \Illuminate\Support\Facades\DB::select('select 1');
            $dbOk = true;
        } catch (\Throwable) {
            $dbOk = false;
        }
        $systemOk = (bool) ($systemActive ?? true) && $dbOk;
    @endphp
    <div class="shell">
        <aside class="sidebar" id="sidebar">
            <div class="brand">
                {{-- برند: عکس خودِ ربات تلگرام. همان اسکریپ t.me که عکس کاربران را
                     می‌آورد، اینجا هم کش می‌شود (TelegramProfileService::botProfile)،
                     پس تایم‌اوت تلگرام صفحه را معطل نمی‌کند و اگر لینک عکس مرده باشد
                     onerror حرف اول نام را نمایان می‌کند. --}}
                <span class="mark{{ ! empty($botProfile['avatar']) ? ' has-photo' : '' }}" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim((string) ($appName ?? '')) !== '' ? (string) $appName : 'C', 0, 1)) }}@if (! empty($botProfile['avatar']))<img src="{{ $botProfile['avatar'] }}" alt="" loading="lazy" onerror="this.remove()">@endif</span>
                <span class="name">
                    {{ $appName ?? 'پنل مدیریت' }}
                    <small class="{{ ! empty($botProfile['username']) ? 'handle' : '' }}">@if (! empty($botProfile['username']))<span dir="ltr">&#64;{{ $botProfile['username'] }}</span>@else Connectix Bot @endif</small>
                    <small class="brand-tag" dir="ltr">VPN RESELLER PANEL</small>
                </span>
                <button type="button" class="nav-close" data-nav-close
                        aria-label="بستن منو">✕</button>
            </div>

            <nav class="pl-nav">
                @foreach ($navGroups as $groupLabel => $keys)
                    @php
                        // یک لینک فقط وقتی رندر می‌شود که مسیرش ثبت شده باشد؛
                        // موج‌های بعدی با ثبتِ مسیرشان، سایدبار را خودبه‌خود
                        // پُر می‌کنند.
                        $groupItems = array_values(array_filter(
                            $keys,
                            fn (string $key): bool => isset($nav[$key]) && $has($nav[$key]['route']),
                        ));
                    @endphp

                    @if ($groupItems !== [])
                        <div class="pl-nav-head">{{ $groupLabel }}</div>
                    @endif

                    @foreach ($groupItems as $key)
                        @php $item = $nav[$key]; @endphp
                        <a href="{{ route($item['route']) }}" class="{{ $active === $key ? 'active' : '' }}">
                            <span class="ico">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">{!! $item['icon'] !!}</svg>
                            </span>
                            <span>{{ $item['label'] }}</span>
                            {{-- نشانِ تیکت‌های باز، فقط روی ردیف تیکت‌ها و فقط
                                 وقتی شمارش بدون خطا عددی بزرگ‌تر از صفر داد —
                                 همان pl-badge فورک. --}}
                            @if ($key === 'tickets' && $openTickets > 0)
                                <span class="pl-badge">{{ \App\Support\PersianCalendar::toPersianDigits((string) $openTickets) }}</span>
                            @endif
                        </a>
                    @endforeach
                @endforeach
            </nav>

            <div class="foot">
                {{-- کارت نسخه به سبک Airoxnet: نسخه از version.txt می‌آید
                     (کامپوزر آن را به‌صورت $panelVersion روی همهٔ ویوها
                     می‌گذارد، با fallback داخل خودش). --}}
                <div class="pl-vercard">
                    <span class="pl-vercard-ico" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2 3 14h7l-1 8 11-13h-7l1-7Z"/></svg>
                    </span>
                    <span class="pl-vercard-text">
                        <b>{{ $appName ?? 'پنل مدیریت' }}</b>
                        <small>نسخهٔ {{ $panelVersion ?? '4.0.0' }}</small>
                    </span>
                </div>

                {{-- کارت «کانال تلگرام» فورک: فقط وقتی کانالی پیکربندی شده
                     باشد. لینک بیرونی است، پس rel="noopener" با آن می‌رود. --}}
                @if ($channelTelegram !== '')
                    <a class="pl-channel" href="https://t.me/{{ $channelTelegram }}"
                       target="_blank" rel="noopener">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.9 4.6 18.9 19c-.2 1-.8 1.3-1.7.8l-4.6-3.4-2.2 2.1c-.3.3-.5.5-1 .5l.3-4.7L18.4 6c.4-.3-.1-.5-.6-.2L7.3 12.4l-4.5-1.4c-1-.3-1-1 .2-1.5l17.2-6.6c.8-.3 1.5.2 1.2 1.7Z"/></svg>
                        <span>کانال تلگرام</span>
                    </a>
                @endif

                <div class="who">
                    <span class="avatar">{{ mb_strtoupper(mb_substr((string) ($admin?->email ?? 'G'), 0, 1)) }}</span>
                    <span>
                        {{ $admin->email ?? '' }}<br>
                        <span class="small">{{ $admin?->role?->label() ?? '' }}</span>
                    </span>
                </div>
                <form action="{{ route('admin.logout') }}" method="post" class="inline-form">
                    @csrf
                    <button type="submit" class="ghost" style="width:100%">خروج از حساب</button>
                </form>
                <p class="pl-credit">توسعه‌دهنده: Connectix</p>
            </div>
        </aside>

        <div class="main">
            <div class="topbar">
                <div class="flex items-center gap-3">
                    <button type="button" class="burger" data-nav-toggle aria-label="باز و بسته کردن منو">☰</button>
                    {{-- صفحات جزئی (مثل پروفایل کاربر) در منوی کناری ردیفی
                         ندارند، پس بدون این دکمه راه برگشتی جز نوار آدرس
                         ندارند. layout با $back فعال می‌شود؛ هر صفحه آدرسِ
                         مادرش را می‌دهد تا لینک بدون تاریخچه هم کار کند
                         (باز کردن مستقیم URL). فلش در RTL به راست می‌چپد چون
                         «قبلی» در این چیدمان سمت راست است. --}}
                    @isset($back)
                        <a href="{{ $back }}" class="icon-btn" title="بازگشت" aria-label="بازگشت به صفحه قبل">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                                 aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                        </a>
                    @endisset
                    <h1>{{ $title ?? '' }}</h1>
                </div>

                {{-- سمت دیگرِ topbar: وضعیت ربات (همان flag که خود ربات
                     می‌خواند)، سلام روز/شب، تاریخ شمسی و ساعت زندهٔ تهران.
                     pill نقطهٔ سبز/قرمز دارد و تاریخ سمت سرور ساخته شده تا با
                     خاموشیِ JS هم خوانده شود. --}}
                <div class="pl-top-tools">
                    <div class="pl-status{{ $systemOk ? '' : ' bad' }}" title="وضعیت ربات">
                        <span class="dot" aria-hidden="true"></span>
                        <span class="pl-status-label">{{ $systemOk ? 'سیستم فعال' : 'سیستم غیرفعال' }}</span>
                    </div>

                    <div class="pl-hello">
                        <span class="pl-hello-ico" id="pl-hello-icon" aria-hidden="true">☀</span>
                        <span class="pl-hello-text">
                            <b id="pl-hello-greeting">{{ \App\Support\JalaliDate::greeting($tehranHour) }}</b>
                            <small dir="ltr" id="pl-clock"
                                   data-epoch="{{ $tehranNow->getTimestamp() }}"
                                   data-offset="{{ $tehranNow->getOffset() }}">{{ $tehranNow->format('H:i:s') }}</small>
                        </span>
                    </div>

                    <span class="pl-date">{{ $jalaliDate }}</span>

                    <div class="tools">
                        <div class="swatches" role="group" aria-label="رنگ پوسته">
                            @foreach (['violet', 'cyan', 'emerald', 'rose', 'amber', 'blue'] as $tone)
                                <button type="button" class="swatch {{ $tone }}" data-accent="{{ $tone }}"
                                        aria-pressed="false" title="پوسته {{ $tone }}"></button>
                            @endforeach
                        </div>
                        <button type="button" class="icon-btn" data-theme-toggle aria-label="تغییر روشنایی">◐</button>
                    </div>
                </div>
            </div>

            <div class="content">
                @if (session('success'))
                    <div class="flash success">{{ session('success') }}</div>
                @endif

                @if (session('warning'))
                    <div class="flash warning">{{ session('warning') }}</div>
                @endif

                @if (session('error'))
                    <div class="flash error">{{ session('error') }}</div>
                @endif

                @if (session('errors') && is_array(session('errors')))
                    <div class="flash error">
                        @foreach (session('errors') as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                @if (session('guide_errors') && is_array(session('guide_errors')))
                    <div class="flash error">
                        @foreach (session('guide_errors') as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                @if ($errors->any())
                    <div class="flash error">{{ $errors->first() }}</div>
                @endif

                @yield('content')
            </div>
        </div>
    </div>

    {{-- تایید عملیات به‌جای confirm() مرورگر: هر فرمی با data-confirm="متن"
         اول همین دیالوگ را نشان می‌دهد. حذف اکانت، حذف آموزش و هر عملیات
         برگشت‌ناپذیر دیگری از همین مسیر می‌رود. --}}
    @include('partials.confirm')

    {{-- جزئیات اکانت روی همه‌ی صفحات در دسترس است: هر المنتی با
         data-client-details="<client id>" همین مودال را باز می‌کند. --}}
    @include('partials.client-details')

    {{-- جزئیات کاربر (#3): آواتار هر ردیف لیست کاربران این مودال را باز می‌کند.
         و جزئیات سفارش (#5.2): دکمه‌ی «جزئیات» در لیست سفارش‌ها. هر دو با
         data-user-details / data-order-details صدا زده می‌شوند و هر دو
         فقط-خواندنی هستند، پس ادمین و ادیتور هر دو می‌توانند بازشان کنند. --}}
    @include('partials.user-details')
    @include('partials.order-details')

    {{-- جزئیات واریز بانکی (#): data-sms-details روی هر سطرِ لیست پیامک‌های
         بانکی این مودال را باز می‌کند. فقط‌خواندنی است، پس ادمین و ادیتور
         هر دو می‌توانند بازش کنند. --}}
    @include('partials.sms-details')

    {{-- تاریخچه‌ی کیف پول و بزرگ‌نمایی عکس. هر دو روی همه‌ی صفحات‌اند: هر
         دکمه‌ای با data-wallet-history یا data-photo همین‌ها را باز می‌کند. --}}
    @include('partials.wallet-history')
    @include('partials.photo-lightbox')

    <script>
        (function () {
            /* The overlay stack.
             *
             * Sheets can be nested (user details from inside an order, the photo
             * lightbox from a header avatar, the confirm dialog over the client
             * sheet), which broke three things that used to be independent
             * per-sheet concerns:
             *
             *  - scroll lock: closing the inner sheet used to unlock the page
             *    while the outer one was still open, so the page scrolled behind
             *    a visible modal;
             *  - Escape: every sheet listens on the document, so one press
             *    closed all of them at once;
             *  - stacking: the sheets carry no z-index of their own, so they
             *    painted in include order - the user sheet is included before
             *    the order sheet and used to open *behind* it.
             *
             * The stack answers all three. It is explicit rather than read back
             * from the DOM because the includes are not in visual order.
             */
            var layerStack = [];
            var LAYER_BASE = 200;   // above the drawer and header (z-index 90)

            window.cxLayerOpen = function (element) {
                if (layerStack.indexOf(element) === -1) {
                    element.style.zIndex = String(LAYER_BASE + layerStack.length * 10);
                    layerStack.push(element);
                }
                document.body.style.overflow = 'hidden';
            };

            window.cxLayerClose = function (element) {
                var at = layerStack.indexOf(element);
                if (at !== -1) {
                    layerStack.splice(at, 1);
                    element.style.removeProperty('z-index');
                }
                if (layerStack.length === 0) document.body.style.overflow = '';
            };

            window.cxIsTopLayer = function (element) {
                return layerStack[layerStack.length - 1] === element;
            };
        })();
    </script>

    <script>
        (function () {
            var root = document.documentElement;

            // Mobile navigation drawer.
            var navToggles = document.querySelectorAll('[data-nav-toggle]');
            var setNav = function (open) {
                document.body.classList.toggle('nav-open', open);
                navToggles.forEach(function (btn) { btn.setAttribute('aria-expanded', String(open)); });
            };
            navToggles.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setNav(!document.body.classList.contains('nav-open'));
                });
            });
            document.querySelectorAll('[data-nav-close]').forEach(function (btn) {
                btn.addEventListener('click', function () { setNav(false); });
            });
            document.addEventListener('click', function (event) {
                if (document.body.classList.contains('nav-open') &&
                    !event.target.closest('#sidebar') && !event.target.closest('[data-nav-toggle]')) {
                    setNav(false);
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') setNav(false);
            });
            // The drawer only exists below 981px; never leave an open drawer
            // locking the page once the layout goes back to the full sidebar.
            var desktopQuery = window.matchMedia('(min-width: 981px)');
            var syncLayout = function () { if (desktopQuery.matches) setNav(false); };
            if (typeof desktopQuery.addEventListener === 'function') desktopQuery.addEventListener('change', syncLayout);
            else if (typeof desktopQuery.addListener === 'function') desktopQuery.addListener(syncLayout);

            // Light / dark.
            var toggle = document.querySelector('[data-theme-toggle]');
            if (toggle) {
                var paint = function () {
                    toggle.textContent = root.dataset.theme === 'light' ? '☾' : '☀';
                    toggle.title = root.dataset.theme === 'light' ? 'حالت تاریک' : 'حالت روشن';
                };
                paint();
                toggle.addEventListener('click', function () {
                    root.dataset.theme = root.dataset.theme === 'light' ? 'dark' : 'light';
                    try { localStorage.setItem('cx-theme', root.dataset.theme); } catch (e) {}
                    paint();
                });
            }

            // Accent palette.
            var swatches = document.querySelectorAll('.swatch[data-accent]');
            var paintSwatches = function () {
                swatches.forEach(function (s) {
                    s.setAttribute('aria-pressed', String(s.dataset.accent === root.dataset.accent));
                });
            };
            paintSwatches();
            swatches.forEach(function (s) {
                s.addEventListener('click', function () {
                    root.dataset.accent = s.dataset.accent;
                    try { localStorage.setItem('cx-accent', s.dataset.accent); } catch (e) {}
                    paintSwatches();
                });
            });

            // Dismissible flash messages. The button is added here rather than
            // in the markup so the first paint is plain server HTML, and the
            // reserved grid column collapses to nothing once it is gone.
            document.querySelectorAll('.flash, .alert').forEach(function (flash) {
                var close = document.createElement('button');
                close.type = 'button';
                close.className = 'flash-x';
                close.setAttribute('aria-label', 'بستن پیام');
                close.textContent = '✕';
                close.addEventListener('click', function () {
                    flash.classList.add('leaving');
                    setTimeout(function () { flash.remove(); }, 220);
                });
                flash.appendChild(close);
            });

            /* File inputs double as drop targets (the browser fills them from
             * a drop natively) so they light up while files are over them.
             * A depth counter, not a bare toggle: the control's internal
             * button fires its own enter/leave pair and a single leave would
             * kill the highlight mid-drag. preventDefault on dragover is what
             * actually allows the drop in every browser. */
            document.querySelectorAll('input[type="file"]').forEach(function (input) {
                var depth = 0;
                var paint = function (on) { input.classList.toggle('is-dragover', on); };
                input.addEventListener('dragover', function (event) { event.preventDefault(); });
                input.addEventListener('dragenter', function () { depth++; paint(true); });
                input.addEventListener('dragleave', function () {
                    if (--depth <= 0) { depth = 0; paint(false); }
                });
                ['drop', 'dragend'].forEach(function (name) {
                    input.addEventListener(name, function () { depth = 0; paint(false); });
                });
            });

            /* Live Tehran clock. The server rendered the real Tehran time as a
             * unix epoch plus its UTC offset; every tick advances the epoch by
             * the seconds elapsed since the script booted and formats it in
             * that fixed offset. The visitor's clock and timezone never enter
             * the math, so the seconds stay on Tehran time. The greeting and
             * the sun/moon glyph flip on the same boundary Airoxnet used
             * (۵ تا ۱۸ روز، بقیه شب). */
            (function () {
                var clock = document.getElementById('pl-clock');
                if (!clock) return;

                var epoch = parseInt(clock.dataset.epoch, 10);
                var offset = parseInt(clock.dataset.offset, 10);
                if (isNaN(epoch) || isNaN(offset)) return;

                var bootMs = Date.now();
                var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
                var paint = function () {
                    // UTC methods + the fixed offset = wall time in Tehran.
                    var now = new Date((epoch + Math.floor((Date.now() - bootMs) / 1000)) * 1000 + offset * 1000);
                    clock.textContent = pad(now.getUTCHours()) + ':' + pad(now.getUTCMinutes()) + ':' + pad(now.getUTCSeconds());

                    var hour = now.getUTCHours();
                    var day = hour >= 5 && hour < 18;
                    var greeting = document.getElementById('pl-hello-greeting');
                    var icon = document.getElementById('pl-hello-icon');
                    if (greeting) greeting.textContent = day ? 'روز بخیر' : 'شب بخیر';
                    if (icon) icon.textContent = day ? '☀' : '☾';
                };

                paint();
                setInterval(paint, 1000);
            })();
        })();
    </script>
</body>
</html>
