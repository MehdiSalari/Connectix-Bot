@if ($stepData['admins'] !== [])
    <h3>مدیران فعلی</h3>
    <table>
        <tr>
            <th>ایمیل</th>
            <th>نقش</th>
            <th>شناسه تلگرام</th>
        </tr>
        @foreach ($stepData['admins'] as $admin)
            <tr>
                <td>{{ $admin['email'] }}</td>
                <td>{{ $admin['role'] === 'admin' ? 'مدیر' : 'ویرایشگر' }}</td>
                <td class="muted">{{ $admin['chat_id'] !== '' ? $admin['chat_id'] : '—' }}</td>
            </tr>
        @endforeach
    </table>
@else
    <div class="alert warn">هنوز هیچ حساب مدیری وجود ندارد. تا وقتی این مرحله تمام نشود، نصب کامل اعلام نمی‌شود.</div>
@endif

<form method="post" action="{{ route('setup.admin') }}">
    @csrf

    <div class="grid">
        <div class="field">
            <label for="email">ایمیل مدیر</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required>
        </div>

        <div class="field">
            <label for="chat_id">شناسه عددی تلگرام (اختیاری)</label>
            <input type="text" id="chat_id" name="chat_id" value="{{ old('chat_id') }}"
                   placeholder="123456789" autocomplete="off">
            <p class="muted">همان مقداری که legacy در ستون <code>chat_id</code> ذخیره می‌کرد.</p>
        </div>

        <div class="field">
            <label for="password">رمز عبور</label>
            <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        </div>

        <div class="field">
            <label for="password_confirmation">تکرار رمز عبور</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required
                   minlength="8" autocomplete="new-password">
        </div>

        <div class="field">
            <label for="role">نقش</label>
            <select id="role" name="role">
                <option value="admin">مدیر کامل (تغییر تنظیمات، کیف پول، سفارش‌ها)</option>
                <option value="editor">ویرایشگر (فقط مشاهده)</option>
            </select>
        </div>
    </div>

    <div class="alert warn">
        اگر ایمیل قبلاً ثبت شده باشد، فقط رمز، نقش، شناسه تلگرام و توکن پنل همان رکورد به‌روزرسانی می‌شود
        (همان رفتار <code>ON DUPLICATE KEY UPDATE</code> در legacy). بقیه مدیران دست‌نخورده می‌مانند.
    </div>

    <div class="actions">
        <button type="submit">ذخیره حساب مدیر</button>
        <a href="{{ route('setup.show', ['step' => $step->previous()->value]) }}">
            <button type="button" class="secondary">مرحله قبل</button>
        </a>
    </div>
</form>
