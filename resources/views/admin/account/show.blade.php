@extends('layouts.admin', ['title' => 'ایمیل و رمز عبور', 'active' => 'account'])

@section('content')
    <div class="card">
        <p class="muted small">
            🔐 برای هر تغییر، رمز عبور فعلی لازم است. این صفحه فقط حساب خودتان را
            تغییر میدهد — هیچ فیلدی برای انتخاب ادمین دیگری در کار نیست.
        </p>
    </div>

    {{-- تغییر ایمیل: action=email همان تقسیمبندی فورک است؛ سرور رمز فعلی
         را با Hash::check میسنجد و ایمیل تکراری را رد میکند. --}}
    <div class="card">
        <h2>تغییر ایمیل</h2>
        <p class="muted small">ایمیل فعلی: <span class="ltr">{{ $admin?->email }}</span></p>
        <form method="post" action="{{ route('admin.account.show') }}" class="form-stack mt-3">
            @csrf
            <input type="hidden" name="action" value="email">
            <div class="row">
                <div>
                    <label for="account-email">ایمیل جدید</label>
                    <input type="email" id="account-email" name="new_email" required dir="ltr"
                           value="{{ old('new_email') }}" placeholder="admin@example.com" autocomplete="email">
                </div>
                <div>
                    <label for="account-email-password">رمز عبور فعلی</label>
                    <input type="password" id="account-email-password" name="current_password" required
                           autocomplete="current-password" placeholder="رمز عبور فعلی">
                </div>
            </div>
            <div><button type="submit">تغییر ایمیل</button></div>
        </form>
    </div>

    {{-- تغییر رمز: حداقل ۸ کاراکتر + تأیید (confirmed) + رمز فعلی؛
         هش توسط cast مدل Admin انجام میشود. --}}
    <div class="card">
        <h2>تغییر رمز عبور</h2>
        <form method="post" action="{{ route('admin.account.show') }}" class="form-stack">
            @csrf
            <input type="hidden" name="action" value="password">
            <div class="row">
                <div>
                    <label for="account-current-password">رمز عبور فعلی</label>
                    <input type="password" id="account-current-password" name="current_password" required
                           autocomplete="current-password" placeholder="رمز عبور فعلی">
                </div>
                <div>
                    <label for="account-new-password">رمز جدید (حداقل ۸ کاراکتر)</label>
                    <input type="password" id="account-new-password" name="new_password" required minlength="8"
                           autocomplete="new-password" placeholder="رمز جدید">
                </div>
            </div>
            <div class="row">
                <div>
                    <label for="account-new-password-confirmation">تکرار رمز جدید</label>
                    <input type="password" id="account-new-password-confirmation" name="new_password_confirmation"
                           required minlength="8" autocomplete="new-password" placeholder="تکرار رمز جدید">
                </div>
                <div></div>
            </div>
            <div><button type="submit">تغییر رمز</button></div>
        </form>
    </div>
@endsection
