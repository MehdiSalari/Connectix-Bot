@extends('layouts.admin', ['title' => 'تنظیمات ربات', 'active' => 'settings'])

@section('content')
    <form method="post" action="{{ route('admin.settings.update') }}">
        @csrf

        <div class="card">
            <div class="cell-user lg">
                <span class="avatar lg">{{ mb_substr(trim((string) $appName) !== '' ? (string) $appName : 'R', 0, 1) }}@if ($bot['avatar'] ?? null)<img src="{{ $bot['avatar'] }}" alt="" loading="lazy" onerror="this.remove()">@endif</span>
                <div>
                    <h2>برند ربات</h2>
                    <p class="muted">@if (! empty($bot['username']))<span dir="ltr">&#64;{{ $bot['username'] }}</span> · @endif{{ $appName }}</p>
                </div>
            </div>
            <div class="row">
                <div>
                    <label>نام ربات</label>
                    <input type="text" name="app_name" value="{{ old('app_name', $effective['app_name']) }}" required maxlength="190">
                </div>
                <div>
                    <label>پشتیبانی (تلگرام)</label>
                    <input type="text" name="telegram_support" value="{{ old('telegram_support', $effective['support_telegram']) }}">
                </div>
                <div>
                    <label>کانال (تلگرام)</label>
                    <input type="text" name="telegram_channel" value="{{ old('telegram_channel', $effective['channel_telegram']) }}">
                </div>
            </div>
            <div class="row">
                <div>
                    <label>آیدی عددی کانال</label>
                    <input type="text" name="telegram_channel_id" value="{{ old('telegram_channel_id', $effective['telegram_channel_id']) }}" placeholder="-1001234567890">
                </div>
                <div>
                    <label>شماره کارت</label>
                    <input type="text" name="card_number" value="{{ old('card_number', $effective['card_number']) }}">
                </div>
                <div>
                    <label>نام دارنده کارت</label>
                    <input type="text" name="card_name" value="{{ old('card_name', $effective['card_name']) }}">
                </div>
            </div>
        </div>

        <div class="card">
            <h2>ادمین‌ها</h2>
            <p class="muted">شناسه‌های عددی تلگرام. ادمین دوم و سوم اختیاری هستند.</p>
            <div class="row">
                <div>
                    <label>ادمین اول</label>
                    <input type="text" name="admin_id" value="{{ old('admin_id', $effective['admin_id']) }}">
                </div>
                <div>
                    <label>ادمین دوم</label>
                    <input type="text" name="admin_id_2" value="{{ old('admin_id_2', $effective['admin_id_2']) }}">
                </div>
                <div>
                    <label>ادمین سوم</label>
                    <input type="text" name="admin_id_3" value="{{ old('admin_id_3', $effective['admin_id_3']) }}">
                </div>
            </div>
        </div>

        <div class="card">
            <h2>متن‌ها</h2>
            <div>
                <label>پیام خوش‌آمدگویی</label>
                <textarea name="welcome_message">{{ old('welcome_message', $effective['messages']['welcome_text'] ?? '') }}</textarea>
            </div>
            <div>
                <label>پیام پشتیبانی</label>
                <textarea name="support_message">{{ old('support_message', $effective['messages']['contact_support'] ?? '') }}</textarea>
            </div>
            <div>
                <label>سوالات متداول</label>
                <textarea name="faq_message">{{ old('faq_message', $effective['messages']['questions_and_answers'] ?? '') }}</textarea>
            </div>
            <div>
                <label>پیام ایجاد اکانت تست</label>
                <textarea name="free_trial_message">{{ old('free_trial_message', $effective['messages']['free_test_account_created'] ?? '') }}</textarea>
            </div>
        </div>

        <div class="card">
            <h2>پرداخت خودکار بانکی</h2>
            <div class="row">
                <div>
                    <label>بانک فعال</label>
                    <select name="bank">
                        <option value="">غیرفعال</option>
                        <option value="blu" {{ ($effective['bank'] ?? '') === 'blu' ? 'selected' : '' }}>بلو بانک</option>
                    </select>
                </div>
                <div>
                    <label>
                        <input type="checkbox" name="bot_notice" value="1" {{ $effective['bank_bot_notice'] ? 'checked' : '' }}>
                        اطلاع به گیت‌وی بانک
                    </label>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>تغییر وضعیت ربات</h2>
            <p class="muted">این تنظیمات همان کلیدهای «test / bot_active / force_channel_join» در فایل bot_config قدیمی هستند.</p>
            <div>
                <label>
                    <input type="checkbox" name="test" value="1" {{ $effective['test'] ? 'checked' : '' }}>
                    فعال بودن اکانت تست رایگان
                </label>
            </div>
            <div>
                <label>
                    <input type="checkbox" name="bot_active" value="1" {{ $effective['bot_active'] ? 'checked' : '' }}>
                    ربات فعال باشد
                </label>
            </div>
            <div>
                <label>
                    <input type="checkbox" name="force_channel_join" value="1" {{ $effective['force_channel_join'] ? 'checked' : '' }}>
                    اجباری بودن عضویت در کانال (به آیدی عددی کانال نیاز دارد)
                </label>
            </div>
        </div>

        <div class="card">
            <button type="submit">ذخیره تنظیمات</button>
        </div>
    </form>
@endsection