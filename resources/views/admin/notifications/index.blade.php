@extends('layouts.admin')

@section('title', 'یادآور و هشدار')

@section('content')
<div class="container mx-auto px-4 py-8 max-w-3xl">
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-8 flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 bg-gradient-to-tr from-rose-500 to-red-600 rounded-2xl flex items-center justify-center text-white text-2xl shadow-lg">
                <i class="fas fa-bell"></i>
            </div>
            <div>
                <h1 class="text-2xl font-black text-gray-800">یادآور انقضا و هشدار حجم مصرفی</h1>
                <p class="text-sm text-gray-500">پیام‌های خودکار پیش از اتمام اشتراک/حجم کاربران</p>
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

    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6 text-sm text-amber-800 space-y-2">
        <p>⚠️ ارسال خودکار این پیامها بر عهدهٔ cron ربات است (بخش ربات، موج بعدی). این صفحه فقط تنظیمات را نگه میدارد و پیشنمایش زیر همان چیزی است که cron اکنون ارسال خواهد کرد.</p>
    </div>

    <form method="POST" class="bg-white rounded-2xl shadow-lg p-6 space-y-6">
        @csrf
        <input type="hidden" name="action" value="save_settings">

        <label class="flex items-center gap-2 cursor-pointer text-sm font-bold text-gray-700">
            <input type="checkbox" name="is_active" value="1" {{ !empty($settings['is_active']) ? 'checked' : '' }} class="w-5 h-5 text-indigo-600 rounded">
            فعال بودن یادآورهای خودکار
        </label>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">یادآوری انقضا (روزهای مانده، با کاما جدا کنید)</label>
            <input type="text" name="expiry_days_before" value="{{ $settings['expiry_days_before'] }}" placeholder="3,1" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-indigo-500">
            <p class="text-xs text-gray-400 mt-1">مثال: <code>3,1</code> یعنی ۳ روز مانده و ۱ روز مانده به انقضا پیام بفرست.</p>
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">هشدار حجم مصرفی (درصد، با کاما جدا کنید)</label>
            <input type="text" name="usage_percent_thresholds" value="{{ $settings['usage_percent_thresholds'] }}" placeholder="80,90" class="w-full border border-gray-300 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-indigo-500">
            <p class="text-xs text-gray-400 mt-1">مثال: <code>80,90</code> یعنی وقتی ۸۰٪ و ۹۰٪ حجم مصرف شد، پیام بفرست.</p>
        </div>

        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-3 rounded-xl transition">
            ذخیره تنظیمات
        </button>
    </form>

    <!-- Preview: due clients -->
    @if (!empty($dueClients))
        <div class="bg-white rounded-2xl shadow-lg p-6 mt-8">
            <h2 class="font-black text-lg mb-4 flex items-center gap-2">
                <i class="fas fa-clock text-rose-600"></i> پیش‌نمایش کاربران واجد شرایط (بر اساس تاریخ انقضا)
            </h2>
            @foreach ($dueClients as $day => $clients)
                <div class="mb-4 p-4 bg-rose-50 border border-rose-200 rounded-xl">
                    <h3 class="font-bold text-rose-700 mb-2">{{ $day }} روز تا انقضا ({{ count($clients) }} کاربر)</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-right">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr><th class="p-2">کاربر</th><th class="p-2">پلن</th><th class="p-2">انقضا</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($clients as $c)
                                    <tr class="border-t">
                                        <td class="p-2">{{ $c['username'] ?? '—' }} <span class="text-xs text-gray-400 font-mono">({{ $c['chat_id'] }})</span></td>
                                        <td class="p-2 font-mono text-xs">{{ $c['id'] }}</td>
                                        <td class="p-2">{{ \App\Services\Shop\FlashSaleAdminService::jalaliDate($c['expire_date'], '/') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- Last sent summary -->
    @if (!empty($lastSent))
        <div class="bg-white rounded-2xl shadow-lg p-6 mt-8">
            <h2 class="font-black text-lg mb-4 flex items-center gap-2">
                <i class="fas fa-history text-indigo-600"></i> اخیرین ارسال‌ها
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-right">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr><th class="p-2">تاریخ</th><th class="p-2">نوع</th><th class="p-2">کاربر/پلن</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($lastSent as $sent)
                            <tr class="border-t">
                                <td class="p-2">{{ \App\Services\Shop\FlashSaleAdminService::jalaliDate($sent['sent_at'], '/') }} {{ $sent['sent_at'] ? date('H:i', strtotime($sent['sent_at'])) : '' }}</td>
                                <td class="p-2">{{ $sent['notif_type'] }}</td>
                                <td class="p-2">{{ $sent['client_id'] }} / {{ $sent['cycle_key'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection