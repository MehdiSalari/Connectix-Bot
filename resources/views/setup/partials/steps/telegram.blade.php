<form method="post" action="{{ route('setup.telegram') }}">
    @csrf

    <div class="field">
        <label for="token">توکن ربات (از BotFather)</label>
        <input type="password" id="token" name="token" required autocomplete="off"
               placeholder="{{ $stepData['hasToken'] ? 'ذخیره شده - برای تغییر مقدار جدید وارد کنید' : '123456789:AAF...' }}">
    </div>

    <div class="field">
        <label for="webhook_url">آدرس webhook</label>
        <input type="url" id="webhook_url" name="webhook_url" value="{{ old('webhook_url', $stepData['webhookUrl']) }}"
               placeholder="https://example.com/telegram/webhook">
        <p class="muted">اگر خالی بماند، از آدرس همین صفحه ساخته می‌شود. تلگرام فقط <code>https</code> می‌پذیرد.</p>
    </div>

    <p class="muted">
        توکن با <code>getMe</code> در تلگرام تست می‌شود؛ اگر نامعتبر باشد این مرحله ذخیره نمی‌شود.
        @if ($stepData['hasSecret'])
            رمز webhook از قبل ساخته شده و همان استفاده می‌شود.
        @else
            یک رمز webhook به‌صورت تصادفی ساخته می‌شود.
        @endif
    </p>

    <div class="actions">
        <button type="submit">تست توکن و ذخیره</button>
        <a href="{{ route('setup.show', ['step' => $step->previous()->value]) }}">
            <button type="button" class="secondary">مرحله قبل</button>
        </a>
    </div>
</form>
