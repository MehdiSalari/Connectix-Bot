<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="dark" data-accent="violet">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark light">
    <title>{{ $appName ?? 'پنل مدیریت' }}{{ isset($title) && $title !== '' ? ' | '.$title : '' }}</title>

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
    <link rel="stylesheet" href="{{ asset('css/connectix.css') }}?v={{ trim((string) config('app.version', '4')) }}">
</head>
<body>
    @php
        $admin = auth('admin')->user();
    @endphp
    <div class="shell">
        <aside class="sidebar" id="sidebar">
            <div class="brand">
                <span class="mark">C</span>
                <span class="name">
                    {{ $appName ?? 'پنل مدیریت' }}
                    <small>Connectix Bot</small>
                </span>
                <button type="button" class="nav-close" data-nav-close
                        aria-label="بستن منو">✕</button>
            </div>

            <nav>
                @php
                    // Icon + label per destination. `$active` is supplied by each view.
                    $nav = [
                        'dashboard' => ['route' => 'admin.dashboard', 'label' => 'داشبورد', 'icon' => '<path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z"/>'],
                        'users'     => ['route' => 'admin.users.index', 'label' => 'کاربران', 'icon' => '<path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-6 1.8-6 4v2h12v-2c0-2.2-2.7-4-6-4Zm8-1a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm.5 3c-.6 0-1.2.1-1.7.3 1.1 1 1.7 2.2 1.7 3.7v2h4v-2c0-2.2-2.2-4-4-4Z"/>'],
                        'orders'    => ['route' => 'admin.orders.index', 'label' => 'سفارش‌ها', 'icon' => '<path d="M6 2h12a1 1 0 0 1 1 1v18l-3-2-2 2-2-2-2 2-2-2-3 2V3a1 1 0 0 1 1-1Zm2 5v2h8V7H8Zm0 4v2h8v-2H8Zm0 4v2h5v-2H8Z"/>'],
                        'wallet'    => ['route' => 'admin.wallet-transactions.index', 'label' => 'تراکنش‌های کیف پول', 'icon' => '<path d="M3 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v1h1a1 1 0 0 1 1 1v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6Zm2 0v12h14V9h-4a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h4V7H5Zm11 6.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/>'],
                        'sms'       => ['route' => 'admin.sms-payments.index', 'label' => 'پیامک‌های بانکی', 'icon' => '<path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2Zm3 4v2h10V8H7Zm0 4v2h7v-2H7Z"/>'],
                        'settings'  => ['route' => 'admin.settings.show', 'label' => 'تنظیمات ربات', 'icon' => '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm9 4a7.5 7.5 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7.6 7.6 0 0 0-2-1.2L16.1 3H11.9l-.4 2.6c-.7.3-1.4.7-2 1.2l-2.4-1-2 3.4 2 1.6a7.7 7.7 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1c.6.5 1.3.9 2 1.2l.4 2.6h4.2l.4-2.6c.7-.3 1.4-.7 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2Z"/>'],
                        'guides'    => ['route' => 'admin.guides.index', 'label' => 'راهنماها', 'icon' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-2 14.5v-9l7 4.5-7 4.5Z"/>'],
                        'broadcast' => ['route' => 'admin.broadcast.show', 'label' => 'پیام همگانی', 'icon' => '<path d="M18 8a3 3 0 0 1 0 6v3a1 1 0 0 1-1.5.9L11 15H7a3 3 0 0 1 0-6h4l5.5-3.9A1 1 0 0 1 18 6v2Zm-9 4a1 1 0 1 0 0 2 1 1 0 0 0 0-2ZM5 9a1 1 0 0 1 1 1v4a1 1 0 1 1-2 0v-4a1 1 0 0 1 1-1Z"/>'],
                    ];
                    $active = $active ?? '';
                @endphp

                @foreach ($nav as $key => $item)
                    <a href="{{ route($item['route']) }}" class="{{ $active === $key ? 'active' : '' }}">
                        <span class="ico">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">{!! $item['icon'] !!}</svg>
                        </span>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            <div class="foot">
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
            </div>
        </aside>

        <div class="main">
            <div class="topbar">
                <div class="flex items-center gap-3">
                    <button type="button" class="burger" data-nav-toggle aria-label="باز و بسته کردن منو">☰</button>
                    <h1>{{ $title ?? '' }}</h1>
                </div>

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

    {{-- جزئیات اکانت روی همه‌ی صفحات در دسترس است: هر المنتی با
         data-client-details="<client id>" همین مودال را باز می‌کند. --}}
    @include('partials.client-details')

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
        })();
    </script>
</body>
</html>
