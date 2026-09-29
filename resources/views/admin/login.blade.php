<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $appName }} | Login</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .main {
            background: #fff;
            width: 100%;
            max-width: 420px;
            border-radius: 16px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            padding: 40px 30px;
        }

        .bot-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            margin: 0 auto 20px auto;
            background: linear-gradient(135deg, #95009f, #667eea);
            color: #fff;
            font-size: 42px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        h2 {
            text-align: center;
            color: #333;
            margin: 0 0 30px 0;
            font-size: 28px;
            font-weight: 600;
        }

        .input-group { margin-bottom: 20px; }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #444;
            font-size: 14px;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e1e1e1;
            border-radius: 12px;
            font-size: 16px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #95009f;
            box-shadow: 0 0 0 4px rgba(149, 0, 159, 0.15);
        }

        input[type="submit"] {
            width: 100%;
            padding: 14px;
            background: #95009f;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.4s ease;
            margin-top: 10px;
        }

        input[type="submit"]:hover { background: #78008c; }

        .error {
            color: #e74c3c;
            text-align: center;
            margin-top: 15px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="main">
        <div class="bot-avatar">C</div>
        <h2>{{ $appName }} Login</h2>

        <form action="{{ route('admin.login.attempt') }}" method="post">
            @csrf
            <div class="input-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="example@domain.com" required>
            </div>

            <div class="input-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
            </div>

            <input type="submit" value="Login">

            @if ($errors->any())
                <p class="error">{{ $errors->first() }}</p>
            @endif
        </form>
    </div>
</body>
</html>