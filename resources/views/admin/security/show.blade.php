@extends('layouts.admin', ['title' => 'امنیت حساب من', 'active' => 'security'])

@section('content')
    <div class="card">
        <p class="muted small">
            🔐 با فعالسازی احراز هویت دو مرحلهای، ورود به پنل علاوه بر رمز عبور نیازمند
            یک کد ۶ رقمی از اپلیکیشن Authenticator شما نیز خواهد بود. این صفحه فقط
            حساب خودتان را تغییر میدهد.
        </p>
    </div>

    {{-- کدهای پشتیبان فقط یکبار — بعد از فعالسازی یا صدور مجدد — نمایش داده
         میشوند؛ فقط هششان در دیتابیس میماند و بازخوانی ندارند. --}}
    @if (! empty($newBackupCodes))
        <div class="card">
            <h2>کدهای پشتیبان (فقط یکبار نمایش داده میشوند)</h2>
            <p class="muted small">
                این کدها را در جای امنی ذخیره کنید. اگر گوشیتان را گم کردید، هرکدام را
                فقط یکبار میتوانید برای ورود استفاده کنید.
            </p>
            <div class="row">
                @foreach ($newBackupCodes as $code)
                    <span class="ltr">{{ $code }}</span>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card">
        @if ($totpEnabled)
            <h2>وضعیت</h2>
            <p>
                <span class="badge ok">احراز هویت دو مرحلهای فعال است</span>
            </p>
            <p class="muted small">{{ $remainingBackupCodes }} کد پشتیبان باقیمانده.</p>

            <form method="post" action="{{ route('admin.security.show') }}" class="form-stack">
                @csrf
                <input type="hidden" name="action" value="regenerate_backup">
                <div>
                    <button type="submit"
                            onclick="return confirm('کدهای پشتیبان قبلی برای همیشه باطل میشوند. ادامه میدهید؟')">
                        صدور کدهای پشتیبان جدید
                    </button>
                </div>
            </form>

            <h3>غیرفعالسازی 2FA</h3>
            <form method="post" action="{{ route('admin.security.show') }}" class="form-stack">
                @csrf
                <input type="hidden" name="action" value="disable">
                <div class="row">
                    <div>
                        <label for="security-disable-password">رمز عبور فعلی</label>
                        <input type="password" id="security-disable-password" name="password" required
                               autocomplete="current-password" placeholder="رمز عبور فعلی">
                    </div>
                    <div></div>
                </div>
                <div><button type="submit" class="btn danger">غیرفعال کن</button></div>
            </form>

        @elseif ($pendingSecret)
            <h2>اسکن کد QR در اپلیکیشن Authenticator</h2>
            <p class="muted small">
                اگر نمیتوانید اسکن کنید، این کلید را دستی وارد کنید:
            </p>
            <p><code class="ltr">{{ $pendingSecret }}</code></p>
            @if ($otpauthUrl)
                <p class="muted small">
                    یا این مسیر را در اپلیکیشن باز کنید:
                </p>
                <p><code class="ltr small">{{ $otpauthUrl }}</code></p>
            @endif

            <form method="post" action="{{ route('admin.security.show') }}" class="form-stack">
                @csrf
                <input type="hidden" name="action" value="confirm_setup">
                <div class="row">
                    <div>
                        <label for="security-setup-code">کد ۶ رقمی از اپلیکیشن</label>
                        <input type="text" id="security-setup-code" name="code" required maxlength="6" pattern="\d{6}"
                               inputmode="numeric" autocomplete="one-time-code" dir="ltr"
                               placeholder="کد ۶ رقمی از اپلیکیشن">
                    </div>
                    <div></div>
                </div>
                <div><button type="submit">تایید و فعالسازی</button></div>
            </form>

        @else
            <h2>احراز هویت دو مرحلهای</h2>
            <p class="muted small">
                احراز هویت دو مرحلهای برای این حساب فعال نیست. با فعالسازی آن، ورود
                به پنل نیازمند یک کد یکبارمصرف از اپلیکیشن Authenticator شما نیز خواهد بود.
            </p>
            <form method="post" action="{{ route('admin.security.show') }}">
                @csrf
                <input type="hidden" name="action" value="start_setup">
                <div><button type="submit">فعالسازی 2FA</button></div>
            </form>
        @endif
    </div>
@endsection
