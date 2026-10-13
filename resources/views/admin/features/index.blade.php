@extends('layouts.admin')

@section('title', 'ویژگیهای ویژه')

@section('content')
<div class="container mx-auto px-4 py-8 max-w-4xl">
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-8 flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 bg-gradient-to-tr from-violet-500 to-fuchsia-600 rounded-2xl flex items-center justify-center text-white text-2xl shadow-lg">
                <i class="fas fa-magic"></i>
            </div>
            <div>
                <h1 class="text-2xl font-black text-gray-800">ویژگیهای ویژه</h1>
                <p class="text-sm text-gray-500">برند، ایموجی استارت، اعتبار خوشآمدگویی و بکاپ دیتابیس</p>
            </div>
        </div>
        <a href="{{ route('admin.dashboard') }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-3 rounded-xl font-bold transition flex items-center gap-2">
            <i class="fas fa-arrow-right"></i> بازگشت به پنل
        </a>
    </div>

    @if ($actionMsg)
        <div class="p-4 rounded-xl mb-6 font-bold {{ $actionMsg['type'] === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white' }}">
            {{ $actionMsg['text'] }}
        </div>
    @endif

    <!-- Brand -->
    <form method="POST" class="bg-white rounded-2xl shadow-lg p-6 mb-6 space-y-4">
        @csrf
        <input type="hidden" name="form" value="brand">
        <h2 class="font-black text-lg"><i class="fas fa-crown ml-2 text-amber-500"></i> برند پنل</h2>
        <div class="grid md:grid-cols-2 gap-4">
            <label class="text-sm font-bold">نام برند
                <input type="text" name="brand_name" maxlength="60" value="{{ $brandName }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
            </label>
            <label class="text-sm font-bold">لینک کانال
                <input type="text" name="brand_channel_url" maxlength="200" dir="ltr" value="{{ $brandChannel }}" placeholder="https://t.me/..." class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
            </label>
        </div>
        <button class="bg-violet-600 hover:bg-violet-700 text-white font-bold px-6 py-2.5 rounded-xl transition">ذخیره برند</button>
    </form>

    <!-- Start emoji -->
    <form method="POST" class="bg-white rounded-2xl shadow-lg p-6 mb-6 space-y-4">
        @csrf
        <input type="hidden" name="form" value="start_emoji">
        <h2 class="font-black text-lg"><i class="fas fa-smile ml-2 text-amber-500"></i> ایموجی ابتدای /start</h2>
        <label class="flex items-center gap-2 text-sm font-bold cursor-pointer">
            <input type="checkbox" name="emoji_enabled" value="1" {{ $startEmoji['enabled'] ? 'checked' : '' }} class="w-5 h-5 rounded">
            نمایش ایموجی در ابتدای پیام خوشآمدگویی
        </label>
        <div class="grid md:grid-cols-2 gap-4">
            <label class="text-sm font-bold">ایموجی (فقط متنی، اگر شناسهٔ پرمیوم ندارید)
                <input type="text" name="emoji_char" maxlength="8" value="{{ $startEmoji['emoji_char'] }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
            </label>
            <label class="text-sm font-bold">شناسهٔ ایموجی پرمیوم (اختیاری)
                <input type="text" name="custom_emoji_id" maxlength="32" dir="ltr" value="{{ $startEmoji['custom_emoji_id'] }}" placeholder="مثلاً 5368324170671202286" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
            </label>
        </div>
        <p class="text-xs text-gray-500">اگر شناسهٔ پرمیوم پر باشد، همان استفاده میشود؛ در غیر این صورت ایموجی متنی نمایش داده میشود.</p>
        <button class="bg-violet-600 hover:bg-violet-700 text-white font-bold px-6 py-2.5 rounded-xl transition">ذخیره ایموجی</button>
    </form>

    <!-- Welcome credit -->
    <form method="POST" class="bg-white rounded-2xl shadow-lg p-6 mb-6 space-y-4">
        @csrf
        <input type="hidden" name="form" value="welcome_credit">
        <h2 class="font-black text-lg"><i class="fas fa-gift ml-2 text-amber-500"></i> اعتبار خوشآمدگویی</h2>
        <label class="flex items-center gap-2 text-sm font-bold cursor-pointer">
            <input type="checkbox" name="wc_enabled" value="1" {{ $welcomeCredit['enabled'] ? 'checked' : '' }} class="w-5 h-5 rounded">
            فعال بودن اعتبار خوشآمدگویی
        </label>
        <div class="grid md:grid-cols-3 gap-4">
            <label class="text-sm font-bold">مبلغ اعتبار (تومان)
                <input type="number" name="wc_amount" min="0" value="{{ $welcomeCredit['amount'] }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
            </label>
            <label class="text-sm font-bold">از تاریخ (اختیاری)
                <input type="datetime-local" name="wc_starts_at" value="{{ $welcomeCredit['starts_at'] !== '' ? str_replace(' ', 'T', $welcomeCredit['starts_at']) : '' }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
            </label>
            <label class="text-sm font-bold">تا تاریخ (اختیاری)
                <input type="datetime-local" name="wc_ends_at" value="{{ $welcomeCredit['ends_at'] !== '' ? str_replace(' ', 'T', $welcomeCredit['ends_at']) : '' }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
            </label>
        </div>
        <label class="flex items-center gap-2 text-sm font-bold cursor-pointer">
            <input type="checkbox" name="wc_notify" value="1" {{ $welcomeCredit['notify'] ? 'checked' : '' }} class="w-5 h-5 rounded">
            بعد از اضافهشدن اعتبار، به کاربر پیام بده
        </label>
        <label class="text-sm font-bold">متن پیام
            <textarea name="wc_message" rows="3" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm">{{ $welcomeCredit['message'] }}</textarea>
            <span class="block text-xs font-normal text-gray-400 mt-1">از <code>{amount}</code> برای درج مبلغ استفاده کنید.</span>
        </label>
        <div class="text-xs text-gray-500 bg-gray-50 rounded-xl p-3">
            شرایط دریافت: کاربر ثبتشده باشد، هیچ خرید موفقی نداشته باشد و قبلاً اعتباری نگرفته باشد.
            <br>تاکنون <b>{{ number_format($welcomeCreditStats['count']) }}</b> کاربر مجموعاً <b>{{ number_format($welcomeCreditStats['total']) }}</b> تومان اعتبار گرفتهاند.
        </div>
        <button class="bg-violet-600 hover:bg-violet-700 text-white font-bold px-6 py-2.5 rounded-xl transition">ذخیره اعتبار خوشآمدگویی</button>
    </form>

    <!-- DB backup -->
    <form method="POST" class="bg-white rounded-2xl shadow-lg p-6 mb-6 space-y-4">
        @csrf
        <input type="hidden" name="form" value="db_backup_schedule">
        <h2 class="font-black text-lg"><i class="fas fa-database ml-2 text-amber-500"></i> بکاپ دیتابیس</h2>
        <label class="flex items-center gap-2 text-sm font-bold cursor-pointer">
            <input type="checkbox" name="bk_enabled" value="1" {{ $backup['enabled'] ? 'checked' : '' }} class="w-5 h-5 rounded">
            ارسال خودکار بکاپ
        </label>
        <div class="grid md:grid-cols-4 gap-4">
            <label class="text-sm font-bold">تناوب
                <select name="bk_frequency" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm bg-white">
                    <option value="daily" {{ $backup['frequency'] === 'daily' ? 'selected' : '' }}>روزانه</option>
                    <option value="weekly" {{ $backup['frequency'] === 'weekly' ? 'selected' : '' }}>هفتگی</option>
                </select>
            </label>
            <label class="text-sm font-bold">ساعت ارسال
                <input type="time" name="bk_time" value="{{ $backup['time'] }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
            </label>
            <label class="text-sm font-bold">روز هفته (برای هفتگی)
                <select name="bk_weekday" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm bg-white">
                    @foreach ([1 => 'شنبه', 2 => 'یکشنبه', 3 => 'دوشنبه', 4 => 'سه‌شنبه', 5 => 'چهارشنبه', 6 => 'پنجشنبه', 7 => 'جمعه'] as $d => $label)
                        <option value="{{ $d }}" {{ (int) $backup['weekday'] === $d ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-bold">گیرنده
                <select name="bk_target" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm bg-white">
                    <option value="admin_id" {{ $backup['target'] === 'admin_id' ? 'selected' : '' }}>ادمین اصلی</option>
                    <option value="admin_id_2" {{ $backup['target'] === 'admin_id_2' ? 'selected' : '' }}>ادمین دوم</option>
                    <option value="admin_id_3" {{ $backup['target'] === 'admin_id_3' ? 'selected' : '' }}>ادمین سوم</option>
                    <option value="custom" {{ $backup['target'] === 'custom' ? 'selected' : '' }}>آیدی دلخواه</option>
                </select>
            </label>
        </div>
        <label class="text-sm font-bold">آیدی چت دلخواه (فقط وقتی گیرنده «دلخواه» است)
            <input type="text" name="bk_custom_chat_id" dir="ltr" value="{{ $backup['custom_chat_id'] }}" class="mt-1 w-full md:w-96 border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
        </label>
        <div class="flex flex-wrap items-center gap-3">
            <button class="bg-violet-600 hover:bg-violet-700 text-white font-bold px-6 py-2.5 rounded-xl transition">ذخیره زمانبندی</button>
            @if ($backup['last_sent_at'] !== '')
                <span class="text-xs text-gray-500">آخرین ارسال موفق: <span class="font-mono">{{ $backup['last_sent_at'] }}</span></span>
            @endif
            @if ($backup['last_error'] !== '')
                <span class="text-xs text-red-600">آخرین خطا: {{ $backup['last_error'] }}</span>
            @endif
        </div>
    </form>

    <form method="POST" class="bg-white rounded-2xl shadow-lg p-6" onsubmit="return confirm('الان یک بکاپ ساخته و برای گیرنده ارسال شود؟');">
        @csrf
        <input type="hidden" name="form" value="db_backup_send_now">
        <button class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-6 py-3 rounded-xl transition">
            <i class="fas fa-download ml-1"></i> ساخت و ارسال بکاپ الان
        </button>
        <p class="text-xs text-gray-500 mt-2">فایل بکاپ به‌صورت اسکریپت SQL در پوشهٔ <code>storage/app/backups</code> ذخیره میشود و به گیرندهٔ انتخابشده فرستاده میشود.</p>
    </form>
</div>
@endsection