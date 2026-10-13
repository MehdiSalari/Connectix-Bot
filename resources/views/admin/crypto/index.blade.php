@extends('layouts.admin', ['title' => 'پرداخت کریپتو', 'active' => 'crypto'])

@section('content')
    <form method="post" action="{{ route('admin.crypto.update') }}" class="form-stack">
        @csrf

        <div class="card">
            <h2>تنظیمات پرداخت کریپتو</h2>
            <p class="muted">ترون (TRX) و تون (TON) با تایید خودکار از روی هش تراکنش. مشتری بعد از واریز، هش یا لینک تراکنش را برای ربات می‌فرستد و ربات آن را روی بلاکچین بررسی می‌کند.</p>

            <div class="row row-spaced">
                <label class="check">
                    <input type="checkbox" name="crypto_enabled" value="1" @checked($settings['crypto_enabled'])>
                    پرداخت کریپتو فعال باشد
                </label>
                <label class="check">
                    <input type="checkbox" name="crypto_auto_confirm" value="1" @checked($settings['crypto_auto_confirm'])>
                    تایید خودکار (خاموش = همه به ادمین)
                </label>
            </div>

            <div class="row">
                <div>
                    <label for="crypto_expiry_minutes">مهلت پرداخت (دقیقه)</label>
                    <input type="number" id="crypto_expiry_minutes" name="crypto_expiry_minutes" min="5" max="1440"
                           value="{{ old('crypto_expiry_minutes', $settings['crypto_expiry_minutes']) }}">
                </div>
                <div>
                    <label for="crypto_min_toman">حداقل مبلغ (تومان)</label>
                    <input type="number" id="crypto_min_toman" name="crypto_min_toman" min="0"
                           value="{{ old('crypto_min_toman', $settings['crypto_min_toman']) }}">
                </div>
                <div>
                    <label for="crypto_nobitex_base">آدرس API نوبیتکس</label>
                    <input type="text" id="crypto_nobitex_base" name="crypto_nobitex_base" dir="ltr"
                           value="{{ old('crypto_nobitex_base', $settings['crypto_nobitex_base']) }}">
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="crypto_custom_usdt_url">منبع سفارشی قیمت تتر (اختیاری، https) — آدرس JSON</label>
                    <input type="text" id="crypto_custom_usdt_url" name="crypto_custom_usdt_url" dir="ltr"
                           value="{{ old('crypto_custom_usdt_url', $settings['crypto_custom_usdt_url']) }}">
                </div>
                <div>
                    <label for="crypto_custom_usdt_text">مسیر عدد قیمت در آن JSON (مثلاً data.price)</label>
                    <input type="text" id="crypto_custom_usdt_text" name="crypto_custom_usdt_text" dir="ltr"
                           value="{{ old('crypto_custom_usdt_text', $settings['crypto_custom_usdt_text']) }}">
                </div>
            </div>
        </div>

        @foreach ($networks as $key => $meta)
            @php $net = $settings[\App\Services\Crypto\CryptoConfigService::NETWORKS_KEY][$key]; @endphp
            <div class="card">
                <h2>{{ $meta['name'] }}</h2>

                <label class="check">
                    <input type="checkbox" name="networks[{{ $key }}][enabled]" value="1" @checked($net['enabled'])>
                    این شبکه فعال باشد
                </label>

                <div class="field">
                    <label for="{{ $key }}_address">آدرس کیف پول دریافت‌کننده ({{ $meta['prefix'] }}…)</label>
                    <input type="text" id="{{ $key }}_address" name="networks[{{ $key }}][address]" dir="ltr" class="ltr"
                           value="{{ old("networks.{$key}.address", $net['address']) }}">
                    @error("{$key}_address")
                        <p class="muted">{{ $message }}</p>
                    @enderror
                </div>

                <div class="row">
                    <div>
                        <label for="{{ $key }}_rate_mode">منبع نرخ</label>
                        <select id="{{ $key }}_rate_mode" name="networks[{{ $key }}][rate_mode]">
                            <option value="manual" @selected($net['rate_mode'] === 'manual')>دستی</option>
                            <option value="auto" @selected($net['rate_mode'] === 'auto')>خودکار (نوبیتکس) + محافظ نرخ دستی</option>
                        </select>
                    </div>
                    <div>
                        <label for="{{ $key }}_rate_toman">نرخ دستی هر {{ $meta['symbol'] }} (تومان)</label>
                        <input type="number" id="{{ $key }}_rate_toman" name="networks[{{ $key }}][rate_toman]" min="0" step="any"
                               value="{{ old("networks.{$key}.rate_toman", $net['rate_toman']) }}">
                    </div>
                </div>

                <div class="row">
                    <div>
                        <label for="{{ $key }}_api_key">API Key — اختیاری ولی برای ترافیک بالا توصیه می‌شود</label>
                        <input type="text" id="{{ $key }}_api_key" name="networks[{{ $key }}][api_key]" dir="ltr"
                               value="{{ old("networks.{$key}.api_key", $net['api_key']) }}">
                    </div>
                    <div>
                        <label for="{{ $key }}_api_base">آدرس API (اگر سرور شما به پیش‌فرض دسترسی ندارد تغییر دهید)</label>
                        <input type="text" id="{{ $key }}_api_base" name="networks[{{ $key }}][api_base]" dir="ltr"
                               value="{{ old("networks.{$key}.api_base", $net['api_base']) }}">
                    </div>
                </div>
            </div>
        @endforeach

        <div class="card">
            <div class="row row-spaced">
                <p class="muted">قبل از استفاده از نرخ خودکار، آن را با نرخ واقعی بازار مقایسه کنید. این صفحه نرخ را زنده صدا نمی‌زند؛ بررسی نرخ روی بلاکچین با خودِ ربات انجام می‌شود.</p>
                <button type="submit">ذخیره تنظیمات</button>
            </div>
        </div>
    </form>
@endsection
