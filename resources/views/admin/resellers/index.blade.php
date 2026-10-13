@extends('layouts.admin', ['title' => 'نمایندگی‌ها', 'active' => 'resellers'])

@section('content')
    {{-- سه کارت همان چیدمان فورک: درخواست‌های در انتظار، نمایندگان، تنظیمات --}}
    <div class="card">
        <h2>نمایندگی‌ها</h2>
        <p class="muted">بررسی درخواست‌های نمایندگی و تنظیم تخفیف نمایندگان</p>
        <div class="row row-spaced">
            <div>
                <label>نمایندگان فعال</label>
                <p class="strong">{{ number_format($stats['active']) }}</p>
            </div>
            <div>
                <label>درخواست‌های در انتظار</label>
                <p class="strong">{{ number_format($stats['pending']) }}</p>
            </div>
            <div>
                <label>مجموع موجودی نمایندگان (تومان)</label>
                <p class="strong">{{ number_format((int) $stats['total_balance']) }}</p>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>درخواست‌های در انتظار</h2>
        <table>
            <thead>
                <tr>
                    <th>کاربر</th>
                    <th>شناسه گفتگو</th>
                    <th>موجودی کیف پول (زمان درخواست)</th>
                    <th>تاریخ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($requests as $request)
                    <tr>
                        <td>{{ $request->user_name ?? ('کاربر '.$request->chat_id) }}</td>
                        <td class="ltr">{{ $request->chat_id }}</td>
                        <td>{{ number_format((int) $request->wallet_balance_at_request) }} <span class="muted">تومان</span></td>
                        <td>{{ $request->getAttribute('created_at_text') ?? '—' }}</td>
                        <td class="cell-actions">
                            <form method="post" action="{{ route('admin.resellers.decide') }}" class="inline-form">
                                @csrf
                                <input type="hidden" name="request_id" value="{{ $request->id }}">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn sm">تایید</button>
                            </form>
                            <form method="post" action="{{ route('admin.resellers.decide') }}" class="inline-form"
                                  data-confirm="رد این درخواست؟"
                                  data-confirm-title="رد درخواست"
                                  data-confirm-accept="رد کن"
                                  data-confirm-tone="soft">
                                @csrf
                                <input type="hidden" name="request_id" value="{{ $request->id }}">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="btn danger sm">رد</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">درخواست در انتظاری وجود ندارد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card" id="resellers-results">
        <form method="get" action="{{ route('admin.resellers.index') }}" class="row">
            <div>
                <label for="reseller-search">جستجو بر اساس شناسه گفتگو</label>
                <input type="text" id="reseller-search" name="search" value="{{ $search }}" placeholder="مثال: 123456789">
            </div>
            <button type="submit" style="margin-left:0">جستجو</button>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>کاربر</th>
                    <th>شناسه گفتگو</th>
                    <th>وضعیت</th>
                    <th>کمیسیون</th>
                    <th>سهمیهٔ تست رایگان</th>
                    <th>مصرف</th>
                    <th>تخفیف</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($resellers as $reseller)
                    @php
                        $limit = $reseller->getAttribute('effective_limit');
                        $discount = $reseller->getAttribute('discount');
                    @endphp
                    <tr>
                        <td>{{ $reseller->getAttribute('user_name') ?? ('کاربر '.$reseller->chat_id) }}</td>
                        <td class="ltr">{{ $reseller->chat_id }}</td>
                        <td>
                            {{-- وضعیت با همان لحن‌های بج صفحات دیگر: فعال سبز،
                                 درخواستِ معلق زرد، لغو/رد قرمز --}}
                            <span class="badge {{ $reseller->status === 'ACTIVE' ? 'ok' : ($reseller->status === 'PENDING' ? 'wait' : 'no') }}">
                                {{ $reseller->status === 'ACTIVE' ? 'فعال' : ($reseller->status === 'PENDING' ? 'در انتظار' : 'لغو شده') }}
                            </span>
                        </td>
                        <td>
                            {{-- فرم کمیسیون درون‌سطری: عدد به‌عنوان درصدِ تخفیف
                                 پیش‌فرض ذخیره می‌شود --}}
                            <form method="post" action="{{ route('admin.resellers.discount') }}" class="inline-form row">
                                @csrf
                                <input type="hidden" name="reseller" value="{{ $reseller->id }}">
                                <input type="hidden" name="type" value="percent">
                                <input type="number" name="value" min="0" max="100" step="0.01"
                                       value="{{ $discount['type'] === 'percent' ? $discount['value'] : 0 }}"
                                       class="ltr" style="width:90px" aria-label="کمیسیون (درصد)">
                                <button type="submit" class="btn ghost sm">ذخیره</button>
                            </form>
                        </td>
                        <td>
                            @if ($limit === -1)
                                نامحدود
                            @elseif ($reseller->test_limit === null)
                                پیش‌فرض
                            @else
                                {{ $limit }}
                            @endif
                        </td>
                        <td>
                            {{ $reseller->getAttribute('effective_used') }}
                            از {{ $limit === -1 ? 'نامحدود' : $limit }}
                            @if ($reseller->getAttribute('monthly'))
                                <span class="muted">(ماهانه)</span>
                            @endif
                        </td>
                        <td>
                            @if ($discount['value'] > 0)
                                {{ $discount['type'] === 'percent' ? number_format($discount['value'], 2).'٪' : number_format((int) $discount['value']).' تومان' }}
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td class="cell-actions">
                            <form method="post" action="{{ route('admin.resellers.quota') }}" class="inline-form row">
                                @csrf
                                <input type="hidden" name="reseller" value="{{ $reseller->id }}">
                                <input type="number" name="test_limit" min="0"
                                       value="{{ ($reseller->test_limit !== null && $limit >= 0) ? $limit : '' }}"
                                       placeholder="پیش‌فرض ({{ $limit === -1 ? 'نامحدود' : $limit }})"
                                       class="ltr" style="width:120px" aria-label="سهمیهٔ تست رایگان">
                                <label class="check">
                                    <input type="checkbox" name="unlimited" value="1" {{ $limit === -1 ? 'checked' : '' }}>
                                    نامحدود
                                </label>
                                <label class="check">
                                    <input type="checkbox" name="monthly" value="1" {{ $reseller->getAttribute('monthly') ? 'checked' : '' }}>
                                    ماهانه
                                </label>
                                <button type="submit" class="btn ghost sm">تنظیم سهمیه</button>
                            </form>
                            <form method="post" action="{{ route('admin.resellers.reset-used') }}" class="inline-form"
                                  data-confirm="مصرف تست رایگان این نماینده صفر شود؟"
                                  data-confirm-title="صفر کردن مصرف"
                                  data-confirm-accept="صفر کن"
                                  data-confirm-tone="soft">
                                @csrf
                                <input type="hidden" name="reseller" value="{{ $reseller->id }}">
                                <button type="submit" class="btn danger sm">صفر کردن مصرف</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">نماینده‌ای یافت نشد.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($resellers->hasPages())
            <div class="pager">
                {{ $resellers->links() }}
            </div>
        @endif
    </div>

    <div class="card">
        <h2>تنظیمات نمایندگی</h2>
        <form method="post" action="{{ route('admin.resellers.settings') }}">
            @csrf
            <div class="row row-spaced">
                <div>
                    <label for="reseller-min-balance">حداقل موجودی کیف پول برای ارسال درخواست نمایندگی (تومان)</label>
                    <input type="number" id="reseller-min-balance" name="reseller_min_wallet_balance"
                           min="0" step="1" value="{{ old('reseller_min_wallet_balance', $settings['reseller_min_wallet_balance'] + 0) }}">
                    <p class="hint">این مبلغ فقط بررسی می‌شود؛ از کیف پول کاربر کسر نخواهد شد.</p>
                </div>
                <div>
                    <label for="reseller-default-limit">سهمیهٔ پیش‌فرض تست رایگان برای هر نماینده</label>
                    <input type="number" id="reseller-default-limit" name="reseller_default_test_limit"
                           min="0" step="1" value="{{ old('reseller_default_test_limit', $settings['reseller_default_test_limit']) }}">
                    <label class="check">
                        <input type="checkbox" name="reseller_default_test_unlimited" value="1" {{ $settings['reseller_default_test_unlimited'] ? 'checked' : '' }}>
                        نامحدود
                    </label>
                </div>
            </div>
            <div>
                <label class="check">
                    <input type="checkbox" name="reseller_monthly_quota" value="1" {{ $settings['reseller_monthly_quota'] ? 'checked' : '' }}>
                    سهمیهٔ ماهانه (مصرف هر ماه شمسی صفر می‌شود؛ اگر خاموش باشد، سهمیه کلی و یک‌بار برای همیشه است)
                </label>
            </div>
            <div class="row row-spaced">
                <div>
                    <label for="reseller-discount-type">تخفیف پیش‌فرض نمایندگان — نوع</label>
                    <select name="reseller_default_discount_type" id="reseller-discount-type">
                        <option value="percent" {{ $settings['reseller_default_discount_type'] === 'percent' ? 'selected' : '' }}>درصدی (٪)</option>
                        <option value="fixed" {{ $settings['reseller_default_discount_type'] === 'fixed' ? 'selected' : '' }}>مبلغ ثابت (تومان)</option>
                    </select>
                </div>
                <div>
                    <label for="reseller-discount-value">تخفیف پیش‌فرض نمایندگان — مقدار</label>
                    <input type="number" id="reseller-discount-value" name="reseller_default_discount_value"
                           min="0" step="0.01" value="{{ old('reseller_default_discount_value', $settings['reseller_default_discount_value'] + 0) }}">
                    <p class="hint">برای نمایندگانی که تخفیف اختصاصی ندارند اعمال می‌شود. کاربران عادی همچنان فقط یک تست می‌گیرند.</p>
                </div>
            </div>
            <button type="submit">ذخیره تنظیمات</button>
        </form>
    </div>
@endsection
