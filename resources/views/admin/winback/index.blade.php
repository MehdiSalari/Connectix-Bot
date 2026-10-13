@extends('layouts.admin')

@section('title', 'بازگشت مشتری')

@section('content')
<div class="container mx-auto px-4 py-8 max-w-4xl">
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-8 flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 bg-gradient-to-tr from-emerald-500 to-teal-600 rounded-2xl flex items-center justify-center text-white text-2xl shadow-lg">
                <i class="fas fa-user-clock"></i>
            </div>
            <div>
                <h1 class="text-2xl font-black text-gray-800">بازگشت مشتری</h1>
                <p class="text-sm text-gray-500">پیام یادآوری و کد تخفیف برای کاربرانی که مدتی غیرفعال بودهاند</p>
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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="bg-white rounded-2xl shadow p-5 text-center">
            <div class="text-3xl font-black text-emerald-600">{{ number_format($eligible) }}</div>
            <div class="text-sm text-gray-500 mt-1">کاربر واجد شرایط (غیرفعال بیش از {{ $settings['inactive_days'] }} روز و بدون خرید موفق)</div>
        </div>
        <div class="bg-white rounded-2xl shadow p-5 text-center">
            <div class="text-3xl font-black text-blue-600">{{ number_format($queued) }}</div>
            <div class="text-sm text-gray-500 mt-1">پیام در صف ارسال</div>
        </div>
        <div class="bg-white rounded-2xl shadow p-5 text-center">
            <span class="px-3 py-1 rounded-full text-sm font-bold {{ !empty($settings['is_active']) ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                {{ !empty($settings['is_active']) ? 'کمپین فعال' : 'کمپین غیرفعال' }}
            </span>
            <div class="text-sm text-gray-500 mt-2">وضعیت کلی کمپین</div>
        </div>
    </div>

    <form method="POST" class="bg-white rounded-2xl shadow-lg p-6 mb-8 space-y-5">
        @csrf
        <input type="hidden" name="action" value="save_settings">

        <label class="flex items-center gap-2 cursor-pointer text-sm font-bold text-gray-700">
            <input type="checkbox" name="is_active" value="1" {{ !empty($settings['is_active']) ? 'checked' : '' }} class="w-5 h-5 text-emerald-600 rounded">
            فعال بودن کمپین بازگشت مشتری
        </label>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">آستانهٔ غیرفعالی (روز)</label>
            <input type="number" name="inactive_days" min="1" max="3650" value="{{ $settings['inactive_days'] }}" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-emerald-500">
            <p class="text-xs text-gray-400 mt-1">کاربرانی که آخرین فعالیتشان بیش از این تعداد روز قبل بوده و هیچ خرید موفقی ندارند، واجد شرایط میشوند.</p>
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">متن پیام بازگشت</label>
            <textarea name="message_text" rows="4" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-emerald-500">{{ $settings['message_text'] }}</textarea>
            <p class="text-xs text-gray-400 mt-1">از <code>{name}</code> برای درج نام کاربر استفاده کنید.</p>
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">کد تخفیف (اختیاری)</label>
            <input type="text" name="coupon_code" value="{{ $settings['coupon_code'] }}" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-emerald-500">
        </div>

        <div class="flex flex-wrap gap-3">
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-6 py-3 rounded-xl transition">
                ذخیره تنظیمات
            </button>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-lg p-6 mb-8">
        <h2 class="font-black text-lg mb-4 flex items-center gap-2">
            <i class="fas fa-play-circle text-emerald-600"></i> اجرای کمپین
        </h2>
        <p class="text-sm text-gray-500 mb-4">با شروع کمپین، برای هر کاربر واجد شرایط یک پیام در صف ارسال قرار میگیرد. ارسال واقعی پیامها توسط cron ربات انجام میشود؛ لغو، پیامهای در انتظار را از صف خارج میکند.</p>
        <div class="flex flex-wrap gap-3">
            <form method="POST" class="inline">
                @csrf
                <input type="hidden" name="action" value="start">
                <button class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-6 py-3 rounded-xl transition">
                    <i class="fas fa-play ml-1"></i> شروع کمپین (صف‌بندی {{ number_format($eligible) }} پیام)
                </button>
            </form>
            <form method="POST" class="inline" onsubmit="return confirm('پیامهای در انتظار از صف خارج شوند؟');">
                @csrf
                <input type="hidden" name="action" value="stop">
                <button class="bg-red-100 hover:bg-red-200 text-red-700 font-bold px-6 py-3 rounded-xl transition">
                    <i class="fas fa-stop ml-1"></i> لغو صف ارسال
                </button>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-lg p-6">
        <h2 class="font-black text-lg mb-4 flex items-center gap-2">
            <i class="fas fa-history text-gray-500"></i> آخرین پیامهای ثبتشده
        </h2>
        @if (empty($logs))
            <p class="text-sm text-gray-400">هنوز پیامی ثبت نشده است.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-right">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="p-2">کاربر</th>
                            <th class="p-2">کد تخفیف</th>
                            <th class="p-2">وضعیت</th>
                            <th class="p-2">زمان</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr class="border-t">
                                <td class="p-2 font-mono text-xs">{{ $log['chat_id'] }}</td>
                                <td class="p-2 font-mono text-xs">{{ $log['coupon_code'] ?: '—' }}</td>
                                <td class="p-2">
                                    @php
                                        $statusColors = [
                                            'QUEUED' => 'bg-blue-100 text-blue-700',
                                            'SENT' => 'bg-green-100 text-green-700',
                                            'FAILED' => 'bg-red-100 text-red-700',
                                            'CANCELLED' => 'bg-gray-100 text-gray-500',
                                        ];
                                        $color = $statusColors[$log['status']] ?? 'bg-gray-100 text-gray-500';
                                    @endphp
                                    <span class="px-2 py-1 rounded-full text-xs font-bold {{ $color }}">{{ $log['status'] }}</span>
                                </td>
                                <td class="p-2 text-xs font-mono">{{ $log['created_at'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection