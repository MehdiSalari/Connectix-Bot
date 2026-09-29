<form method="post" action="{{ route('setup.bot-config') }}">
    @csrf

    <p class="muted">
        این مقادیر همان چیزی است که legacy در <code>setup/bot_config.json</code> می‌نوشت. اگر خالی بمانند،
        مقدار پنل فروشنده استفاده می‌شود؛ هر مقداری که اینجا بنویسید مقدار محلی می‌شود و بر پنل مقدم است.
    </p>

    @unless ($stepData['hasToken'])
        <div class="alert warn">بدون توکن پنل، خواندن خودکار تنظیمات از پنل ممکن نیست. مقادیر را دستی وارد کنید.</div>
    @endunless

    <div class="grid">
        <div class="field">
            <label for="app_name">نام برنامه</label>
            <input type="text" id="app_name" name="app_name"
                   value="{{ old('app_name', $stepData['current']['app_name'] ?? '') }}" maxlength="120">
        </div>

        <div class="field">
            <label for="support_telegram">پشتیبانی تلگرام</label>
            <input type="text" id="support_telegram" name="support_telegram"
                   value="{{ old('support_telegram', $stepData['current']['support_telegram'] ?? '') }}" maxlength="120">
        </div>

        <div class="field">
            <label for="channel_telegram">کانال تلگرام</label>
            <input type="text" id="channel_telegram" name="channel_telegram"
                   value="{{ old('channel_telegram', $stepData['current']['channel_telegram'] ?? '') }}" maxlength="120">
        </div>

        <div class="field">
            <label for="card_number">شماره کارت</label>
            <input type="text" id="card_number" name="card_number"
                   value="{{ old('card_number', $stepData['current']['card_number'] ?? '') }}" maxlength="64">
        </div>

        <div class="field">
            <label for="card_name">نام صاحب کارت</label>
            <input type="text" id="card_name" name="card_name"
                   value="{{ old('card_name', $stepData['current']['card_name'] ?? '') }}" maxlength="120">
        </div>
    </div>

    <h3>سوییچ‌ها</h3>
    <label class="check">
        <input type="checkbox" name="active" value="1" @checked($stepData['current']['bot_active'] ?? true)>
        <span>ربات فعال باشد</span>
    </label>
    <label class="check">
        <input type="checkbox" name="test" value="1" @checked($stepData['current']['test'] ?? true)>
        <span>حساب آزمایشی رایگان فعال باشد</span>
    </label>
    <label class="check">
        <input type="checkbox" name="force_channel_join" value="1" @checked($stepData['current']['force_channel_join'] ?? false)>
        <span>عضویت در کانال اجباری باشد</span>
    </label>

    <div class="actions">
        <button type="submit" name="action" value="save">ذخیره تنظیمات</button>
        <button type="submit" name="action" value="fetch" class="secondary">خواندن از پنل</button>
        <a href="{{ route('setup.show', ['step' => $step->previous()->value]) }}">
            <button type="button" class="secondary">مرحله قبل</button>
        </a>
    </div>
</form>
