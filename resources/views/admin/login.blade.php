<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="dark" data-accent="violet">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark light">
    <title>{{ $appName }} | Login</title>

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
    <link rel="stylesheet" href="{{ asset('css/connectix.css') }}?v={{ \App\Support\AssetVersion::css() }}">
</head>
<body>
    <div class="auth">
        <div class="auth-card auth-card-glass">
            {{-- برند: عکس خودِ ربات (همان منبع کش‌شدهٔ t.me که سایدبار هم
                 می‌خواند)؛ بدون عکس، حرف اول نام می‌نشیند. --}}
            <div class="bot-avatar{{ ! empty($botProfile['avatar'] ?? null) ? ' has-photo' : '' }}">
                {{ mb_strtoupper(mb_substr(trim((string) ($appName ?? 'C')) !== '' ? (string) $appName : 'C', 0, 1)) }}
                @if (! empty($botProfile['avatar'] ?? null))
                    <img src="{{ $botProfile['avatar'] }}" alt="" loading="lazy" onerror="this.remove()">
                @endif
            </div>

            @if ($twoFactor ?? false)
                {{-- مرحله دوم ورود (verify_2fa.php فورک): رمز قبلاً قبول شده و
                     فقط کد دوم لازم است؛ هیچ سشنی باز نشده تا کد درست بیاید. --}}
                <h2>Two-Factor Verification</h2>
                <p class="sub" id="promptText">Enter the 6-digit code from your authenticator app</p>

                <form action="{{ route('admin.login.2fa') }}" method="post">
                    @csrf
                    <input type="hidden" name="use_backup" id="useBackupField" value="0">
                    <div class="input-group">
                        <label for="code" id="codeLabel">Authentication Code</label>
                        <input type="text" id="code" name="code" placeholder="000000" maxlength="9"
                               required autofocus autocomplete="one-time-code" inputmode="numeric" dir="ltr">
                    </div>

                    <input type="submit" value="Verify">

                    @if ($errors->any())
                        <p class="error">{{ $errors->first() }}</p>
                    @endif
                </form>

                <p class="muted small center">
                    <a href="#" id="toggleLink" onclick="toggleBackup(); return false;">Use a backup code instead</a>
                </p>
                <p class="muted small center">
                    <a href="{{ route('admin.login.2fa.cancel') }}">Cancel and go back to login</a>
                </p>

                <script>
                    (function () {
                        var usingBackup = false;
                        window.toggleBackup = function () {
                            usingBackup = !usingBackup;
                            document.getElementById('useBackupField').value = usingBackup ? '1' : '0';
                            document.getElementById('codeLabel').textContent = usingBackup ? 'Backup Code' : 'Authentication Code';
                            document.getElementById('promptText').textContent = usingBackup
                                ? 'Enter one of your one-time backup codes'
                                : 'Enter the 6-digit code from your authenticator app';
                            document.getElementById('code').placeholder = usingBackup ? 'XXXX-XXXX' : '000000';
                            document.getElementById('code').maxLength = usingBackup ? 9 : 6;
                            document.getElementById('toggleLink').textContent = usingBackup ? 'Use authenticator code instead' : 'Use a backup code instead';
                        };
                    })();
                </script>
            @else
                <h2>{{ $appName }} Login</h2>
                <p class="sub">پنل مدیریت ربات — برای ادامه وارد شوید</p>
                <p class="brand-tag" dir="ltr">VPN RESELLER PANEL</p>

                <form action="{{ route('admin.login.attempt') }}" method="post">
                    @csrf
                    <div class="input-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}"
                               placeholder="example@domain.com" required autofocus autocomplete="username">
                    </div>

                    <div class="input-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="••••••••"
                               required autocomplete="current-password">
                    </div>

                    <input type="submit" value="Login">

                    @if ($errors->any())
                        <p class="error">{{ $errors->first() }}</p>
                    @endif
                </form>
            @endif

            <p class="muted auth-tools">
                <button type="button" class="icon-btn" data-theme-toggle aria-label="تغییر روشنایی">◐</button>
            </p>
        </div>
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
