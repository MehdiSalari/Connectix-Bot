<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $appName }} | Admin</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f4f4f7;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        header {
            background: linear-gradient(135deg, #95009f, #667eea);
            color: #fff;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        header .who { font-size: 14px; opacity: 0.95; }

        main { padding: 32px 24px; max-width: 900px; margin: 0 auto; }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
            padding: 24px;
            margin-bottom: 20px;
        }

        .card h2 { margin: 0 0 12px 0; font-size: 18px; color: #333; }

        .card p { margin: 4px 0; color: #555; font-size: 15px; }

        .logout {
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 14px;
            cursor: pointer;
        }

        .logout:hover { background: rgba(255, 255, 255, 0.32); }
    </style>
</head>
<body>
    <header>
        <h1>{{ $appName }} Admin Panel</h1>
        <div class="who">
            {{ $admin->email }} · {{ $admin->role->label() }} ·
            <form action="{{ route('admin.logout') }}" method="post" style="display:inline">
                @csrf
                <button type="submit" class="logout">Logout</button>
            </form>
        </div>
    </header>

    <main>
        <div class="card">
            <h2>Dashboard</h2>
            <p>The panel sections arrive in the next phase.</p>
        </div>
    </main>
</body>
</html>