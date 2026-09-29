<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'نصب') | Connectix</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0 0 60px 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #eef2ff 0%, #f5f0ff 100%);
            color: #2b2b2b;
            font-family: Vazirmatn, 'Segoe UI', Tahoma, sans-serif;
            font-size: 15px;
            line-height: 1.9;
        }

        .wrap { max-width: 900px; margin: 0 auto; padding: 0 16px; }

        header {
            background: linear-gradient(135deg, #95009f 0%, #667eea 100%);
            color: #fff;
            padding: 26px 0 22px 0;
            margin-bottom: 26px;
        }

        header h1 { margin: 0; font-size: 24px; font-weight: 700; }
        header p { margin: 6px 0 0 0; opacity: .9; font-size: 14px; }

        .card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(31, 41, 55, .08);
            padding: 24px;
            margin-bottom: 20px;
        }

        .card h2 { margin: 0 0 6px 0; font-size: 19px; }
        .card h3 { margin: 22px 0 10px 0; font-size: 16px; color: #444; }
        .hint { color: #6b7280; font-size: 13px; margin: 0 0 18px 0; }

        .steps { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 18px; }

        .steps a, .steps span {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #f3f4f6;
            color: #6b7280;
            text-decoration: none;
        }

        .steps .current { background: #95009f; color: #fff; font-weight: 700; }
        .steps .done { background: #dcfce7; color: #166534; }

        .bar { height: 8px; background: #e5e7eb; border-radius: 999px; overflow: hidden; margin-bottom: 20px; }
        .bar > div { height: 100%; background: linear-gradient(90deg, #a78bfa, #6366f1); }

        label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px; }

        input[type=text], input[type=email], input[type=password], input[type=url], input[type=number], select {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 15px;
            background: #fff;
            color: #111;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #a78bfa;
            box-shadow: 0 0 0 4px rgba(167, 139, 250, .18);
        }

        .field { margin-bottom: 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0 16px; }
        .check { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; font-weight: 500; }
        .check input { width: 18px; height: 18px; }

        button {
            background: #95009f;
            color: #fff;
            border: 0;
            border-radius: 10px;
            padding: 12px 22px;
            font-size: 15px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
        }

        button:hover { background: #78008c; }
        button.secondary { background: #4b5563; }
        button.secondary:hover { background: #374151; }
        button.danger { background: #b91c1c; }
        button.danger:hover { background: #991b1b; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 8px; }

        .alert { border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-size: 14px; }
        .alert.ok { background: #dcfce7; color: #14532d; border: 1px solid #86efac; }
        .alert.bad { background: #fee2e2; color: #7f1d1d; border: 1px solid #fca5a5; }
        .alert.warn { background: #fef3c7; color: #78350f; border: 1px solid #fcd34d; }

        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: right; padding: 8px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
        th { color: #6b7280; font-weight: 600; }

        .row-ok::before { content: '✓'; color: #16a34a; font-weight: 700; margin-left: 6px; }
        .row-no::before { content: '✗'; color: #dc2626; font-weight: 700; margin-left: 6px; }
        .row-warn::before { content: '!'; color: #d97706; font-weight: 700; margin-left: 6px; }

        .log {
            background: #111827;
            color: #4ade80;
            font-family: Consolas, 'Courier New', monospace;
            font-size: 12.5px;
            direction: ltr;
            text-align: left;
            padding: 14px;
            border-radius: 10px;
            max-height: 320px;
            overflow: auto;
            white-space: pre-wrap;
        }

        code { background: #f3f4f6; border-radius: 6px; padding: 1px 6px; font-size: 13px; }
        .muted { color: #6b7280; font-size: 13px; }
        ul.plain { list-style: none; padding: 0; margin: 8px 0; }
    </style>
</head>
<body>
    <header>
        <div class="wrap">
            <h1>نصب Connectix</h1>
            <p>راه‌اندازی اولیه، پیکربندی و اتصال به پنل فروش Connectix</p>
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

        <p class="muted" style="text-align:center">
            وضعیت: {{ $progress['state']->label() }}
        </p>
    </div>
</body>
</html>
