<div class="alert {{ $stepData['hasToken'] ? 'ok' : 'warn' }}">
    @if ($stepData['hasToken'])
        توکن پنل ذخیره شده است. اگر فروشنده دیگری را وارد می‌کنید، همان مقدار جایگزین می‌شود.
    @else
        هنوز توکنی برای پنل ثبت نشده است. با ایمیل و رمز فروشنده وارد شوید یا توکن را مستقیم بنویسید.
    @endif
</div>

<form method="post" action="{{ route('setup.connectix') }}">
    @csrf

    <h3>ورود با حساب فروشنده</h3>
    <p class="muted">همان کاری که نسخه legacy با متد <code>/v1/seller/auth/login</code> می‌کرد.</p>

    <div class="grid">
        <div class="field">
            <label for="email">ایمیل فروشنده</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}"
                   placeholder="example@connectix.vip" autocomplete="off">
        </div>

        <div class="field">
            <label for="panel_password">رمز عبور پنل</label>
            <input type="password" id="panel_password" name="password" autocomplete="new-password">
        </div>
    </div>

    <h3>یا توکن مستقیم</h3>
    <p class="muted">اگر توکن را از جای دیگری دارید، این بخش را پر کنید و بخش بالا را خالی بگذارید.</p>

    <div class="grid">
        <div class="field">
            <label for="token">توکن API</label>
            <input type="password" id="token" name="token" autocomplete="off">
        </div>

        <div class="field">
            <label for="base_url">آدرس API</label>
            <input type="url" id="base_url" name="base_url" value="{{ old('base_url', $stepData['baseUrl']) }}">
        </div>
    </div>

    <p class="muted">توکن در فایل <code>.env</code> ذخیره می‌شود، نه در کد و نه در فایلی داخل پوشه وب، و هرگز دوباره نمایش داده نمی‌شود.</p>

    <div class="actions">
        <button type="submit">آزمایش اتصال و ذخیره</button>
        <a href="{{ route('setup.show', ['step' => $step->previous()->value]) }}">
            <button type="button" class="secondary">مرحله قبل</button>
        </a>
    </div>
</form>
