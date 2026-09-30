<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="dark" data-accent="violet">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark light">
    <title>@yield('title', 'نصب') | Connectix</title>

    <script>
        (function () {
            try {
                var stored = localStorage.getItem('cx-theme');
                document.documentElement.dataset.theme = stored ||
                    (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
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
    <style>
        /* Installer-only spacing on top of the shared design system. */
        body { padding-bottom: 60px; }
        .card { padding: 26px; }
        .card h2 { margin-bottom: 6px; }
        .card h3 { margin-top: 22px; }
        .field + .field { margin-top: 4px; }
        .grid { align-items: start; }
    </style>
</head>
<body>
    <header class="hero">
        <div class="wrap flex items-center justify-between gap-4">
            <div>
                <h1>نصب Connectix</h1>
                <p>راه‌اندازی اولیه، پیکربندی و اتصال به پنل فروش Connectix</p>
            </div>
            <button type="button" class="icon-btn" data-theme-toggle aria-label="تغییر روشنایی"
                    style="background:rgba(255,255,255,.18);border-color:rgba(255,255,255,.35);color:#fff">◐</button>
        </div>
    </header>

    <div class="wrap">
        @php
            /**
             * One sentence per step: what this step is for, in the operator's
             * terms. Written here rather than in the controller so the wording
             * can be changed without touching installation logic.
             */
            $hints = [
                \App\Enums\SetupStep::Requirements->value => 'بررسی نسخه PHP، افزونه‌ها، دسترسی نوشتن پوشه‌ها و کلید برنامه. اگر موردی قرمز باشد، همان قدم اول را در هاست یا php.ini درست کنید.',
                \App\Enums\SetupStep::Database->value => 'مشخصات دیتابیسی که نصب روی آن انجام می‌شود. قبل از ذخیره، اتصال آزمایش می‌شود و هیچ چیزی حذف نمی‌شود.',
                \App\Enums\SetupStep::Migrations->value => 'ساخت جدول‌های لازم. فقط جدول‌های نبوده ساخته می‌شوند؛ هیچ دستور حذف‌کننده‌ای اجرا نمی‌شود.',
                \App\Enums\SetupStep::Connectix->value => 'اتصال به پنل فروش Connectix. می‌توانید ایمیل و رمز فروشنده را بدهید تا توکن بگیریم، یا توکن را مستقیم وارد کنید.',
                \App\Enums\SetupStep::Telegram->value => 'توکن ربات با متد getMe در تلگرام تست می‌شود. اگر توکن اشتباه باشد، نصب کامل اعلام نمی‌شود.',
                \App\Enums\SetupStep::Webhook->value => 'آدرسی که تلگرام پیام‌ها را به آن می‌فرستد. تلگرام فقط آدرس https می‌پذیرد.',
                \App\Enums\SetupStep::Admin->value => 'حساب مدیر پنل. رمز با الگوریتم Laravel ذخیره می‌شود و اگر ایمیل تکراری باشد فقط همان یک رکورد به‌روزرسانی می‌شود.',
                \App\Enums\SetupStep::BotConfig->value => 'نام برنامه، پشتیبانی، کانال و اطلاعات کارت. مقادیر پنل به‌صورت خودکار خوانده می‌شوند و هر کدام قابل تغییر است.',
                \App\Enums\SetupStep::Import->value => 'انتقال اطلاعات از نصب قبلی و خواندن اطلاعات مشتریان از پنل. هر دو فقط رکورد اضافه می‌کنند و تکرارشان بی‌خطر است.',
                \App\Enums\SetupStep::Complete->value => 'بررسی نهایی همه بخش‌ها، شامل تماس واقعی با تلگرام و پنل. اگر مورد بحرانی وجود داشته باشد، نصب کامل اعلام نمی‌شود.',
            ];
            $hint = $hints[$step->value] ?? '';
        @endphp

        @include('setup.partials.notices')

        <div class="card">
            <div class="bar">
                <div style="width: {{ $progress['percent'] }}%"></div>
            </div>

            <div class="steps">
                @foreach ($steps as $index => $item)
                    @if ($item->value === $step->value)
                        <span class="current">{{ $item->label() }}</span>
                    @elseif ($index < $progress['step']->position() - 1)
                        <a class="done" href="{{ route('setup.show', ['step' => $item->value]) }}">{{ $item->label() }}</a>
                    @else
                        <span>{{ $item->label() }}</span>
                    @endif
                @endforeach
            </div>

            <h2>{{ $step->title() }}</h2>
            <p class="hint">{{ $hint }}</p>

            @yield('content')
        </div>

        <p class="muted center">
            وضعیت: {{ $progress['state']->label() }}
        </p>
    </div>

    <script>
        (function () {
            var root = document.documentElement;
            var toggle = document.querySelector('[data-theme-toggle]');
            if (!toggle) return;

            var paint = function () {
                toggle.textContent = root.dataset.theme === 'light' ? '☾' : '☀';
            };
            paint();

            toggle.addEventListener('click', function () {
                root.dataset.theme = root.dataset.theme === 'light' ? 'dark' : 'light';
                try { localStorage.setItem('cx-theme', root.dataset.theme); } catch (e) {}
                paint();
            });
        })();
    </script>
</body>
</html>
