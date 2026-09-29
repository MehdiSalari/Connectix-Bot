<div class="alert {{ $stepData['url'] !== '' ? 'ok' : 'warn' }}">
    آدرس: <code>{{ $stepData['url'] !== '' ? $stepData['url'] : 'هنوز ثبت نشده' }}</code>
    @if (! $stepData['hasSecret'])
        <br>رمز webhook هنوز ساخته نشده است؛ در مرحله قبل ساخته می‌شود.
    @endif
</div>

<form method="post" action="{{ route('setup.webhook') }}">
    @csrf

    <p class="muted">
        گزینه اول webhook را در تلگرام ثبت می‌کند (یا آدرس قبلی را جایگزین می‌کند) و گزینه دوم فقط بررسی می‌کند
        که تلگرام چه چیزی ثبت کرده است. اگر سایت پشت پروکسی یا Cloudflare است، همان چیزی را ثبت کنید که در
        تنظیمات <code>TELEGRAM_WEBHOOK_URL</code> ذخیره شده است.
    </p>

    <div class="actions">
        <button type="submit" name="action" value="register">ثبت webhook در تلگرام</button>
        <button type="submit" name="action" value="check" class="secondary">فقط بررسی کن</button>
        <a href="{{ route('setup.show', ['step' => $step->previous()->value]) }}">
            <button type="button" class="secondary">مرحله قبل</button>
        </a>
    </div>
</form>
