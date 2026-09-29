<form method="post" action="{{ route('setup.database') }}">
    @csrf

    <div class="alert warn">
        این نصب روی درایور <code>{{ $stepData['driver'] }}</code> انجام می‌شود. اگر این دیتابیس از قبل داده دارد، نگران نباشید:
        جدول‌های موجود دست‌نخورده می‌مانند و هیچ رکوردی حذف یا بازنشانی نمی‌شود.
    </div>

    <div class="grid">
        @if ($stepData['driver'] === 'sqlite')
            <div class="field">
                <label for="name">مسیر فایل دیتابیس</label>
                <input type="text" id="name" name="name" value="{{ old('name', $stepData['name']) }}"
                       placeholder="database/database.sqlite" required>
            </div>
        @else
            <div class="field">
                <label for="host">آدرس سرور</label>
                <input type="text" id="host" name="host" value="{{ old('host', $stepData['host']) }}"
                       placeholder="localhost" required>
            </div>

            <div class="field">
                <label for="port">پورت</label>
                <input type="text" id="port" name="port" value="{{ old('port', $stepData['port']) }}"
                       placeholder="3306" required>
            </div>

            <div class="field">
                <label for="name">نام دیتابیس</label>
                <input type="text" id="name" name="name" value="{{ old('name', $stepData['name']) }}"
                       placeholder="connectix_bot" required>
            </div>

            <div class="field">
                <label for="username">نام کاربری</label>
                <input type="text" id="username" name="username" value="{{ old('username', $stepData['username']) }}"
                       required autocomplete="off">
            </div>

            <div class="field">
                <label for="password">رمز عبور</label>
                <input type="password" id="password" name="password"
                       placeholder="{{ $stepData['hasPassword'] ? '•••••••• (ذخیره شده)' : 'رمز عبور' }}"
                       autocomplete="new-password">
                <p class="muted">اگر خالی بماند، رمز فعلی تغییر نمی‌کند.</p>
            </div>

            <div class="field">
                <label class="check">
                    <input type="checkbox" name="create_database" value="1">
                    <span>اگر دیتابیس وجود نداشت ساخته شود (Create database if missing)</span>
                </label>
            </div>
        @endif
    </div>

    <div class="actions">
        <button type="submit">آزمایش اتصال و ذخیره</button>
        <a href="{{ route('setup.show', ['step' => $step->previous()->value]) }}">
            <button type="button" class="secondary">مرحله قبل</button>
        </a>
    </div>
</form>
