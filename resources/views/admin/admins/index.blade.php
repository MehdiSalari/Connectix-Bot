@extends('layouts.admin', ['title' => 'ادمین‌ها', 'active' => 'admins'])

@section('content')
    <div class="card">
        <p class="muted small">
            🔐 هر ادمین فقط بخش‌هایی را می‌بیند و می‌تواند باز کند که تیک دسترسی‌اش را داشته باشد (هم منو و هم خود صفحه‌ها و درخواست‌های پشت‌صحنه قفل می‌شوند).
            @if ($me?->is_primary)
                <br>شما <b>ادمین اصلی</b> هستید: تنها حسابی که ساختش به زمان نصب برمی‌گردد و هیچ‌کس نمی‌تواند آن را غیرفعال یا تغییر دهد.
            @else
                <br>حساب ادمین اصلی (ساخته‌شده در زمان نصب) قابل غیرفعال‌سازی یا تغییر توسط هیچ‌کس نیست.
            @endif
        </p>
    </div>

    {{-- افزودن ادمین: همان فرم فورک — ایمیل، رمز ۸ کاراکتری، نقش، آیدی
         تلگرام اختیاری و تیک دسترسی‌ها. تیک‌ها با تغییر نقش به پیش‌فرض همان
         نقش برمی‌گردند (اسکریپت پایین صفحه) و بعد می‌توان دستی عوضشان کرد. --}}
    <details class="card">
        <summary class="strong cursor-pointer">افزودن ادمین جدید</summary>
        <form method="post" action="{{ route('admin.admins.store') }}" class="form-stack mt-3">
            @csrf
            <div class="row">
                <div>
                    <label for="admin-email">ایمیل</label>
                    <input type="email" id="admin-email" name="email" required dir="ltr" placeholder="admin@example.com">
                </div>
                <div>
                    <label for="admin-password">رمز عبور (حداقل ۸ کاراکتر)</label>
                    <input type="password" id="admin-password" name="password" required minlength="8" autocomplete="new-password">
                </div>
            </div>
            <div class="row">
                <div>
                    <label for="admin-chat">آیدی عددی تلگرام (اختیاری)</label>
                    <input type="text" id="admin-chat" name="chat_id" dir="ltr" inputmode="numeric" placeholder="123456789">
                </div>
                <div>
                    <label for="admin-role">نقش</label>
                    <select name="role" id="admin-role" class="role-select">
                        <option value="{{ \App\Enums\AdminRole::Admin->value }}">{{ \App\Enums\AdminRole::Admin->label() }}</option>
                        <option value="{{ \App\Enums\AdminRole::Editor->value }}">{{ \App\Enums\AdminRole::Editor->label() }}</option>
                    </select>
                </div>
            </div>
            <div class="perm-box">
                <div class="strong">دسترسی‌ها <span class="muted small">(با تغییر نقش، تیک‌ها به پیش‌فرض آن نقش برمی‌گردند؛ بعد می‌توانید دستی تغییر دهید.)</span></div>
                <div class="grid sm:grid-cols-2 gap-1.5 mt-2">
                    @foreach ($permissions as $key => $label)
                        <label class="check">
                            <input type="checkbox" name="permissions[]" value="{{ $key }}" class="perm-cb">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div><button type="submit">ایجاد ادمین</button></div>
        </form>
    </details>

    @forelse ($admins as $a)
        @php
            $isSelf = $me !== null && (int) $me->id === (int) $a->id;
            $isPrim = (bool) $a->is_primary;
            // ادمین اصلی برای هیچ‌کس قابل ویرایش نیست؛ فرم‌هایش چاپ نمی‌شوند.
            $locked = $isPrim;
            $effective = \App\Services\Panel\AdminPermissionService::permissionsFor($a);
            $count = count(array_intersect($effective, array_keys($permissions)));
        @endphp
        <div class="card">
            <div class="row items-center justify-between">
                <div>
                    <p class="strong ltr">{{ $a->email }}</p>
                    <p class="muted small">
                        {{ $a->role->label() }}
                        — دسترسی: {{ $a->role->value === 'admin' && ! $isPrim ? 'کامل' : $count.' بخش' }}
                        @if ($a->chat_id !== '' && $a->chat_id !== null)
                            — ID: <span class="ltr">{{ $a->chat_id }}</span>
                        @endif
                    </p>
                </div>
                <div class="actions">
                    @if ($isPrim)
                        <span class="badge wait">ادمین اصلی</span>
                    @endif
                    @if ($isSelf)
                        <span class="badge">شما</span>
                    @endif
                    <span class="badge {{ $a->is_active ? 'ok' : 'no' }}">{{ $a->is_active ? 'فعال' : 'غیرفعال' }}</span>
                </div>
            </div>

            @if (! $locked)
                <details class="mt-2">
                    <summary class="cursor-pointer small strong">ویرایش دسترسی‌ها و عملیات</summary>

                    {{-- تیک دسترسی‌ها: لیست صریح همین ادمین را بازنویسی می‌کند.
                         برای ادمینِ نقش کامل فرم چاپ نمی‌شود — ادمین کامل به
                         تیکِ جداگانه نیازی ندارد. --}}
                    @if ($a->role->value !== 'admin')
                        <form method="post" action="{{ route('admin.admins.permissions', $a) }}" class="form-stack mt-3">
                            @csrf
                            <div class="grid sm:grid-cols-2 gap-1.5">
                                @foreach ($permissions as $key => $label)
                                    <label class="check">
                                        <input type="checkbox" name="permissions[]" value="{{ $key }}"
                                               class="perm-cb" {{ in_array($key, $effective, true) ? 'checked' : '' }}>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div><button type="submit">ذخیرهٔ دسترسی‌ها</button></div>
                        </form>
                    @endif

                    <div class="actions mt-3">
                        @unless ($isSelf)
                            <form method="post" action="{{ route('admin.admins.toggle', $a) }}" class="inline-form">
                                @csrf
                                <button type="submit" class="btn ghost">{{ $a->is_active ? 'غیرفعال‌سازی' : 'فعال‌سازی' }}</button>
                            </form>
                        @endunless

                        <form method="post" action="{{ route('admin.admins.password', $a) }}" class="inline-form">
                            @csrf
                            <label for="new-password-{{ $a->id }}" class="muted small">رمز جدید</label>
                            <input type="password" id="new-password-{{ $a->id }}" name="password"
                                   minlength="8" required placeholder="رمز جدید" autocomplete="new-password"
                                   style="width:12rem">
                            <button type="submit">تغییر رمز</button>
                        </form>
                    </div>
                </details>
            @else
                <p class="muted small mt-2">🔒 {{ $isSelf ? 'حساب خودتان است؛ از صفحه حساب برای تغییر رمز خودتان استفاده کنید.' : 'حساب ادمین اصلی قابل تغییر یا حذف نیست.' }}</p>
            @endif
        </div>
    @empty
        <div class="card"><p class="muted">ادمینی ثبت نشده است.</p></div>
    @endforelse
@endsection

{{-- با تغییر نقش در فرمِ ساخت، تیک‌ها به پیش‌فرض همان نقش برمی‌گردند —
     همان رفتار data-defaults فورک. --}}
@php $defaultsPayload = json_encode($defaults, JSON_UNESCAPED_UNICODE); @endphp
<div hidden data-role-defaults="{{ $defaultsPayload }}"></div>
<script>
(function () {
    var box = document.querySelector('[data-role-defaults]');
    if (!box) return;
    var defaults;
    try { defaults = JSON.parse(box.getAttribute('data-role-defaults') || '{}'); } catch (e) { return; }

    var grid = document.querySelector('.perm-box');
    var select = document.querySelector('.role-select');
    if (!grid || !select) return;

    select.addEventListener('change', function () {
        var preset = defaults[select.value] || [];
        grid.querySelectorAll('.perm-cb').forEach(function (cb) {
            cb.checked = preset.indexOf(cb.value) !== -1;
        });
    });
})();
</script>
