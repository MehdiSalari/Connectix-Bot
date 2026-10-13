@extends('layouts.admin', ['title' => 'پلن سفارشی', 'active' => 'custom-plan'])

@section('content')
    <form method="post" action="{{ route('admin.custom-plan.update') }}" class="form-stack">
        @csrf

        <div class="card">
            <h2>پلن سفارشی (حجم و زمان دلخواه)</h2>
            <p class="muted">قیمت هر گیگابایت/روز و محدودیت‌های قابل انتخاب کاربر. پنل Connectix در حال حاضر API برای ساخت خودکار پلن جدید ندارد؛ با تایید هر درخواست، ادمین باید پلن را دستی روی پنل بسازد و اطلاعات اکانت را به ربات بدهد.</p>

            <label class="check">
                <input type="checkbox" name="is_active" value="1" @checked($settings['is_active'])>
                فعال بودن دکمه «حجم و زمان دلخواه» در ربات
            </label>

            <div class="row">
                <div>
                    <label for="price_per_gb">قیمت هر گیگابایت (تومان)</label>
                    <input type="number" id="price_per_gb" name="price_per_gb" min="0" step="1"
                           value="{{ old('price_per_gb', $settings['price_per_gb']) }}">
                </div>
                <div>
                    <label for="price_per_day">قیمت هر روز (تومان)</label>
                    <input type="number" id="price_per_day" name="price_per_day" min="0" step="1"
                           value="{{ old('price_per_day', $settings['price_per_day']) }}">
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="min_gb">حداقل حجم قابل انتخاب (گیگابایت)</label>
                    <input type="number" id="min_gb" name="min_gb" min="0.1" step="0.1"
                           value="{{ old('min_gb', $settings['min_gb']) }}">
                </div>
                <div>
                    <label for="max_gb">حداکثر حجم قابل انتخاب (گیگابایت)</label>
                    <input type="number" id="max_gb" name="max_gb" min="0.1" step="0.1"
                           value="{{ old('max_gb', $settings['max_gb']) }}">
                    @error('max_gb')
                        <p class="muted">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="min_days">حداقل مدت زمانی (روز)</label>
                    <input type="number" id="min_days" name="min_days" min="1"
                           value="{{ old('min_days', $settings['min_days']) }}">
                </div>
                <div>
                    <label for="max_days">حداکثر مدت زمانی (روز)</label>
                    <input type="number" id="max_days" name="max_days" min="1"
                           value="{{ old('max_days', $settings['max_days']) }}">
                    @error('max_days')
                        <p class="muted">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="field">
                <label for="default_devices">تعداد دستگاه پیش‌فرض برای پلن‌های سفارشی</label>
                <input type="number" id="default_devices" name="default_devices" min="1"
                       value="{{ old('default_devices', $settings['default_devices']) }}">
                <p class="muted hint">این عدد فقط برای اطلاع خودتان هنگام ساخت پلن روی پنل Connectix نمایش داده می‌شود.</p>
            </div>

            <div class="field">
                <label for="text_ask_gb">پیام درخواست حجم دلخواه (اختیاری)</label>
                <textarea id="text_ask_gb" name="text_ask_gb" rows="2"
                    placeholder="📐 لطفاً حجم دلخواه خود را به گیگابایت وارد کنید.">{{ old('text_ask_gb', $settings['text_ask_gb']) }}</textarea>
            </div>

            <div class="field">
                <label for="text_ask_days">پیام درخواست انتخاب مدت زمان (اختیاری)</label>
                <textarea id="text_ask_days" name="text_ask_days" rows="2"
                    placeholder="⏳ حالا مدت زمان دلخواه را انتخاب کنید 👇🏻">{{ old('text_ask_days', $settings['text_ask_days']) }}</textarea>
            </div>

            <div class="field">
                <label for="text_summary_title">عنوان خلاصه سفارش (قبل از تایید پرداخت)</label>
                <textarea id="text_summary_title" name="text_summary_title" rows="1"
                    placeholder="📋 خلاصه سفارش شما:">{{ old('text_summary_title', $settings['text_summary_title']) }}</textarea>
            </div>

            <div class="hint">
                <strong>مثال:</strong> با تنظیمات فعلی، یک درخواست {{ $exampleGb }} گیگابایت / {{ $exampleDays }} روز، قیمتش می‌شود
                <b>{{ number_format($examplePrice) }} تومان</b>.
            </div>

            <button type="submit">ذخیره تنظیمات</button>
        </div>
    </form>
@endsection