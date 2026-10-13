@extends('layouts.admin', ['title' => 'درگاه‌های پرداخت', 'active' => 'gateways'])

@section('content')
    <div class="card">
        <h2>درگاه‌های پرداخت آنلاین</h2>
        <p class="muted">فعال/غیرفعال کردن، تنظیم کلید API و حداقل مبلغ هر درگاه. درگاهی که کلیدش خالی باشد به کاربران نشان داده نمی‌شود.</p>

        @forelse ($gateways as $gateway)
            @php $driver = $gateway->gateway_name; @endphp
            <form method="post" action="{{ route('admin.gateways.update') }}" class="form-stack" style="margin-top:16px">
                @csrf
                <input type="hidden" name="gateway_name" value="{{ $driver }}">

                <div class="cell-user">
                    <div>
                        <h2>{{ $gateway->title ?? $drivers[$driver] ?? $driver }}</h2>
                        <p class="muted">
                            <span class="badge {{ $gateway->is_active ? 'ok' : 'no' }}">{{ $gateway->is_active ? 'فعال' : 'غیرفعال' }}</span>
                            @if ($gateway->is_active && trim((string) ($gateway->api_key ?? '')) === '' && trim((string) ($gateway->merchant_id ?? '')) === '')
                                <span class="badge wait">کلید API تنظیم نشده</span>
                            @endif
                        </p>
                    </div>
                </div>

                <p class="muted hint">{{ $hints[$driver] ?? '' }}</p>

                @if ($driver === 'zarinpal')
                    <div class="field">
                        <label for="merchant_id_{{ $driver }}">مرچنت‌کد (Merchant ID)</label>
                        <input type="text" id="merchant_id_{{ $driver }}" name="merchant_id" dir="ltr" autocomplete="off"
                               value="{{ old('merchant_id', $gateway->merchant_id) }}" maxlength="190">
                    </div>
                @else
                    <div class="field">
                        <label for="api_key_{{ $driver }}">{{ $keyLabels[$driver] ?? 'کلید API (ApiKey)' }}</label>
                        <input type="text" id="api_key_{{ $driver }}" name="api_key" dir="ltr" autocomplete="off"
                               value="{{ old('api_key', $gateway->api_key) }}" maxlength="500">
                    </div>
                @endif

                @if ($driver === 'nowpayments')
                    <div class="field">
                        <label for="usd_rate_toman">نرخ دلار (تومان) — مبلغ سفارش بر این اساس به دلار تبدیل می‌شود</label>
                        <input type="number" id="usd_rate_toman" name="usd_rate_toman" min="0"
                               value="{{ old('usd_rate_toman', ($gateway->config_json ? (json_decode((string) $gateway->config_json, true)['usd_rate_toman'] ?? 0) : 0)) }}">
                    </div>
                @endif

                @if ($driver !== 'nowpayments')
                    <div class="field">
                        <label for="currency_{{ $driver }}">واحد مبلغی که به درگاه ارسال می‌شود</label>
                        <select id="currency_{{ $driver }}" name="currency">
                            <option value="IRT" @selected(($gateway->currency ?? 'IRT') === 'IRT')>تومان</option>
                            <option value="IRR" @selected(($gateway->currency ?? 'IRT') === 'IRR')>ریال (۱۰ برابر تومان)</option>
                        </select>
                    </div>
                @endif

                <div class="field">
                    <label for="sandbox_{{ $driver }}">حالت آزمایشی</label>
                    <label class="check">
                        <input type="checkbox" name="sandbox" value="1" @checked((bool) $gateway->sandbox)>
                        حالت آزمایشی (Sandbox) — فقط برای تست؛ پرداخت واقعی انجام نمی‌شود
                    </label>
                </div>

                <div class="field">
                    <label for="min_amount_{{ $driver }}">حداقل مبلغ قابل پرداخت (تومان)</label>
                    <input type="number" id="min_amount_{{ $driver }}" name="min_amount" min="0"
                           value="{{ old('min_amount', (int) $gateway->min_amount) }}">
                </div>

                <div class="row row-spaced">
                    <label class="check">
                        <input type="checkbox" name="is_active" value="1" @checked((bool) $gateway->is_active)>
                        نمایش این روش پرداخت به کاربران
                    </label>
                    <button type="submit">ذخیره تغییرات</button>
                </div>
            </form>

            {{-- کلید فعال/غیرفعال سریع، بیرون از فرم ذخیره (فرم را نمی‌توان در فرم تودرتو کرد). --}}
            <form method="post" action="{{ route('admin.gateways.toggle') }}" class="inline-form" style="margin-top:8px">
                @csrf
                <input type="hidden" name="gateway_name" value="{{ $driver }}">
                <input type="hidden" name="active" value="{{ $gateway->is_active ? '0' : '1' }}">
                <button type="submit" class="mini-btn">{{ $gateway->is_active ? 'غیرفعال کردن سریع' : 'فعال‌سازی سریع' }}</button>
            </form>
        @empty
            <p class="muted">هیچ درگاه پرداختی یافت نشد.</p>
        @endforelse
    </div>
@endsection
