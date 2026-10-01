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
                        'guides'    => ['route' => 'admin.guides.index', 'label' => 'آموزش‌ها', 'icon' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-2 14.5v-9l7 4.5-7 4.5Z"/>'],
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
        })();
    </script>
</body>
</html>
