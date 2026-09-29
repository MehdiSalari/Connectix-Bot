@include('setup.partials.checks')

@if ($installationFailed ?? false)
    <div class="alert bad">
        نصب کامل اعلام نشد. موارد قرمز بالا باید درست شوند و دکمه زیر دوباره اجرا شود.
    </div>
@endif

<form method="post" action="{{ route('setup.complete') }}">
    @csrf
    <div class="actions">
        <button type="submit">بررسی نهایی و پایان نصب</button>
        <a href="{{ route('setup.show', ['step' => $step->previous()->value]) }}">
            <button type="button" class="secondary">مرحله قبل</button>
        </a>
    </div>
</form>

<h3>شروع مجدد نصب</h3>
<p class="muted">
    این دکمه فقط وضعیت نصب‌کننده را پاک می‌کند تا مراحل دوباره بررسی شوند.
    دیتابیس، حساب مدیر و تنظیمات <strong>حذف نمی‌شوند</strong>؛ چون وضعیت واقعی از روی همین‌ها دوباره محاسبه می‌شود،
    معمولاً بعد از این دکمه مستقیم به صفحه گزارش برمی‌گردید.
</p>

<form method="post" action="{{ route('setup.reset') }}">
    @csrf
    <div class="field">
        <label class="check">
            <input type="checkbox" name="confirm" value="1">
            <span>می‌دانم که این کار هیچ داده‌ای را حذف نمی‌کند و فقط مراحل نصب دوباره بررسی می‌شوند.</span>
        </label>
    </div>
    <div class="actions">
        <button type="submit" class="danger">پاک کردن وضعیت نصب</button>
    </div>
</form>

<p class="muted">فایل وضعیت: <code>{{ $stateFile }}</code></p>
