<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $appName ?? 'پنل مدیریت' }} | {{ $title ?? '' }}</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: #eef1f6;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        .shell { display: flex; min-height: 100vh; }

        .sidebar {
            width: 232px;
            background: linear-gradient(180deg, #5b1a63 0%, #43104b 100%);
            color: #fff;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar .brand {
            padding: 22px 20px 18px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            font-size: 17px;
            font-weight: 700;
        }

        .sidebar .brand small { display: block; font-weight: 400; font-size: 12px; opacity: 0.75; }

        .sidebar nav a {
            display: block;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            padding: 12px 22px;
            font-size: 14px;
            border-right: 3px solid transparent;
        }

        .sidebar nav a:hover, .sidebar nav a.active {
            background: rgba(255, 255, 255, 0.08);
            border-right-color: #ffb647;
        }

        .sidebar .foot {
            margin-top: auto;
            padding: 16px 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            font-size: 13px;
        }

        .sidebar .foot .who { opacity: 0.85; margin-bottom: 8px; }

        .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }

        .topbar {
            background: #fff;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e4e8f0;
        }

        .topbar h1 { margin: 0; font-size: 18px; color: #333; }

        .content { padding: 24px; max-width: 1100px; width: 100%; margin: 0 auto; }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
            padding: 22px;
            margin-bottom: 20px;
        }

        button, .btn {
            display: inline-block;
            background: #6b1a75;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 9px 18px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }

        button:hover, .btn:hover { background: #5b1550; }

        .btn.danger { background: #c0392b; }
        .btn.danger:hover { background: #a93226; }
        .btn.ghost { background: #f0eef6; color: #5b1a63; }
        .btn.ghost:hover { background: #e2ddef; }

        table { width: 100%; border-collapse: collapse; font-size: 14px; }

        th, td { padding: 10px 12px; text-align: right; border-bottom: 1px solid #edf0f5; }

        th { color: #667; font-weight: 600; background: #fafbfd; }

        tr:hover td { background: #f8f9fb; }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            background: #eef0f4;
            color: #444;
        }

        .badge.ok { background: #d9f2e2; color: #146b38; }
        .badge.wait { background: #fff3d6; color: #8a6d1a; }
        .badge.no { background: #fde3e2; color: #9c2b25; }

        label { display: block; font-size: 13px; font-weight: 600; color: #444; margin: 12px 0 6px 0; }

        input[type="text"], input[type="number"], input[type="password"],
        input[type="email"], input[type="url"], textarea, select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e1e1e1;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
        }

        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #95009f;
        }

        textarea { min-height: 110px; resize: vertical; }

        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 22px; }

        .stat {
            background: #fff;
            border-radius: 12px;
            padding: 18px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
        }

        .stat .k { font-size: 13px; color: #889; }
        .stat .v { font-size: 24px; font-weight: 700; color: #333; margin-top: 6px; }

        .flash { border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; font-size: 14px; }

        .flash.success { background: #d9f2e2; color: #146b38; }
        .flash.warning { background: #fff3d6; color: #8a6d1a; }
        .flash.error { background: #fde3e2; color: #9c2b25; }

        .pager { margin-top: 16px; display: flex; gap: 8px; justify-content: center; align-items: center; font-size: 14px; }

        .muted { color: #889; font-size: 13px; }

        .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }

        .row > div { flex: 1; min-width: 180px; }

        .inline-form { display: inline-block; }

        .inline-form button { padding: 5px 12px; font-size: 13px; margin: 0 2px; }
    </style>
</head>
<body>
    @php($admin = auth('admin')->user())
    <div class="shell">
        <aside class="sidebar">
            <div class="brand">
                {{ $appName ?? 'پنل مدیریت' }}
                <small>Connectix Bot</small>
            </div>
            <nav>
                <a href="{{ route('admin.dashboard') }}" class="{{ ($active = $active ?? '') === 'dashboard' ? 'active' : '' }}">🏠 داشبورد</a>
                <a href="{{ route('admin.users.index') }}" class="{{ $active === 'users' ? 'active' : '' }}">👥 کاربران</a>
                <a href="{{ route('admin.orders.index') }}" class="{{ $active === 'orders' ? 'active' : '' }}">🧾 سفارش‌ها</a>
                <a href="{{ route('admin.wallet-transactions.index') }}" class="{{ $active === 'wallet' ? 'active' : '' }}">👝 تراکنش‌های کیف پول</a>
                <a href="{{ route('admin.sms-payments.index') }}" class="{{ $active === 'sms' ? 'active' : '' }}">💳 پیامک‌های بانکی</a>
                <a href="{{ route('admin.settings.show') }}" class="{{ $active === 'settings' ? 'active' : '' }}">⚙️ تنظیمات ربات</a>
                <a href="{{ route('admin.guides.index') }}" class="{{ $active === 'guides' ? 'active' : '' }}">📹 مدیریت راهنماها</a>
                <a href="{{ route('admin.broadcast.show') }}" class="{{ $active === 'broadcast' ? 'active' : '' }}">📣 پیام همگانی</a>
            </nav>
            <div class="foot">
                <div class="who">
                    {{ $admin->email ?? '' }} · {{ $admin?->role?->label() ?? '' }}
                </div>
                <form action="{{ route('admin.logout') }}" method="post" class="inline-form">
                    @csrf
                    <button type="submit" class="btn ghost">خروج</button>
                </form>
            </div>
        </aside>

        <div class="main">
            <div class="topbar">
                <h1>{{ $title ?? '' }}</h1>
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
</body>
</html>