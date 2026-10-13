@extends('layouts.admin', ['title' => 'کد تخفیف', 'active' => 'coupons'])

@section('content')
    @php $editing = $editing ?? null; @endphp

    <div class="card">
        <h2>{{ $editing ? 'ویرایش کد تخفیف محلی' : 'ساخت کد تخفیف محلی' }}</h2>
        <p class="muted">این کدها مستقل از پنل Connectix در همین دیتابیس ذخیره می‌شوند و در ربات کار می‌کنند. زمان‌ها به وقت تهران است.</p>

        <form method="post" action="{{ route('admin.coupons.store') }}" class="form-stack">
            @csrf
            @if ($editing)
                <input type="hidden" name="original_code" value="{{ $editing['code'] }}">
            @endif

            <div class="row">
                <div>
                    <label for="coupon-code">کد</label>
                    <input type="text" id="coupon-code" name="code" dir="ltr" required maxlength="40"
                           placeholder="WELCOME20" value="{{ old('code', $editing['code'] ?? '') }}">
                    @error('code')
                        <p class="muted">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="coupon-kind">نوع تخفیف</label>
                    <select id="coupon-kind" name="kind">
                        <option value="percent" @selected(old('kind', $editing['kind'] ?? 'percent') === 'percent')>درصدی</option>
                        <option value="fixed" @selected(old('kind', $editing['kind'] ?? 'percent') === 'fixed')>مبلغ ثابت (تومان)</option>
                    </select>
                </div>
                <div>
                    <label for="coupon-value">مقدار</label>
                    <input type="number" id="coupon-value" name="value" required min="1"
                           value="{{ old('value', $editing['value'] ?? '') }}">
                    @error('value')
                        <p class="muted">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="coupon-starts">شروع (اختیاری)</label>
                    <input type="datetime-local" id="coupon-starts" name="starts_at"
                           value="{{ old('starts_at', isset($editing['starts_at']) && $editing['starts_at'] ? substr(str_replace(' ', 'T', (string) $editing['starts_at']), 0, 16) : '') }}">
                </div>
                <div>
                    <label for="coupon-ends">پایان (اختیاری)</label>
                    <input type="datetime-local" id="coupon-ends" name="ends_at"
                           value="{{ old('ends_at', isset($editing['ends_at']) && $editing['ends_at'] ? substr(str_replace(' ', 'T', (string) $editing['ends_at']), 0, 16) : '') }}">
                    @error('ends_at')
                        <p class="muted">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="coupon-max-uses">سقف تعداد استفاده (خالی یا صفر = بدون سقف)</label>
                    <input type="number" id="coupon-max-uses" name="max_uses" min="0"
                           value="{{ old('max_uses', $editing['max_uses'] ?? '') }}">
                </div>
            </div>

            <label class="check">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editing ? (bool) $editing['is_active'] : true))>
                فعال
            </label>

            <div class="row row-spaced">
                <button type="submit">ذخیره کد</button>
                @if ($editing)
                    <a href="{{ route('admin.coupons.index') }}" class="btn ghost">انصراف</a>
                @endif
            </div>
        </form>
    </div>

    <div class="card">
        <h2>کدهای محلی</h2>

        @if ($coupons === [])
            <p class="muted">هنوز کد تخفیفی نساخته‌اید.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>کد</th>
                            <th>تخفیف</th>
                            <th>بازه</th>
                            <th>استفاده</th>
                            <th>وضعیت</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($coupons as $coupon)
                            <tr>
                                <td class="ltr">{{ $coupon['code'] }}</td>
                                <td>{{ $coupon['kind'] === 'percent' ? ((int) $coupon['value']).'٪' : number_format((int) $coupon['value']).' تومان' }}</td>
                                <td class="muted">{{ $coupon['starts_at'] ?? '—' }} ← {{ $coupon['ends_at'] ?? '—' }}</td>
                                <td>{{ $coupon['used_count'] }}{{ (int) $coupon['max_uses'] > 0 ? ' / '.(int) $coupon['max_uses'] : '' }}</td>
                                <td><span class="badge {{ $coupon['is_active'] ? 'ok' : 'no' }}">{{ $coupon['is_active'] ? 'فعال' : 'غیرفعال' }}</span></td>
                                <td class="cell-actions">
                                    {{-- سه عملیات ردیف: تغییر وضعیت، ویرایش و حذف.
                                         فرم‌ها در یک سطر نمی‌توانند تودرتو باشند؛ هر
                                         کدام فرم مستقل خودش است. --}}
                                    <form method="post" action="{{ route('admin.coupons.toggle') }}" class="inline-form">
                                        @csrf
                                        <input type="hidden" name="code" value="{{ $coupon['code'] }}">
                                        <input type="hidden" name="on" value="{{ $coupon['is_active'] ? '0' : '1' }}">
                                        <button type="submit" class="mini-btn">{{ $coupon['is_active'] ? 'غیرفعال' : 'فعال‌سازی' }}</button>
                                    </form>
                                    <a href="{{ route('admin.coupons.index', ['edit' => $coupon['code']]) }}" class="mini-btn">ویرایش</a>
                                    <form method="post" action="{{ route('admin.coupons.destroy') }}" class="inline-form"
                                          data-confirm="کد تخفیف «{{ $coupon['code'] }}» حذف می‌شود و دیگر در ربات کار نمی‌کند."
                                          data-confirm-title="حذف کد تخفیف"
                                          data-confirm-accept="حذف کن">
                                        @csrf
                                        <input type="hidden" name="code" value="{{ $coupon['code'] }}">
                                        <button type="submit" class="mini-btn danger">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection