@extends('layouts.admin')

@section('title', 'حراج زمان‌دار')

@section('content')
<div class="container mx-auto px-4 py-8 max-w-5xl">
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-8 flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 bg-gradient-to-tr from-rose-500 to-orange-500 rounded-2xl flex items-center justify-center text-white text-2xl shadow-lg">
                <i class="fas fa-bolt"></i>
            </div>
            <div>
                <h1 class="text-2xl font-black text-gray-800">حراج زمان‌دار</h1>
                <p class="text-sm text-gray-500">تخفیف زمان‌دار روی هر پلن؛ قیمت خط‌خورده و زمان باقی‌مانده در ربات نمایش داده می‌شود</p>
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

    <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 mb-6 text-sm text-indigo-800 leading-7">
        💡 زمان‌ها به وقت تهران است. کد تخفیف روی قیمت حراج هم اعمال می‌شود. اگر نماینده‌ای تخفیف اختصاصی هم داشته باشد، هر کدام که قیمت کمتری بدهد اعمال می‌شود (جمع نمی‌شوند).
    </div>

    <form method="POST" class="bg-white rounded-2xl shadow-lg p-6 mb-8 grid gap-4 md:grid-cols-2">
        @csrf
        <input type="hidden" name="action" value="create">

        <div class="md:col-span-2">
            <label class="block text-sm font-bold text-gray-700 mb-1">پلن</label>
            <select name="plan_id" required class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
                @foreach ($plans as $plan)
                    <option value="{{ $plan['id'] }}">{{ $plan['title'] }} — {{ number_format($plan['sell_price'] ?? 0) }} تومان</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">نوع تخفیف</label>
            <select name="discount_type" class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
                <option value="percent">درصد</option>
                <option value="fixed">مبلغ ثابت (تومان)</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">مقدار تخفیف</label>
            <input type="number" min="1" name="discount_value" required placeholder="مثلاً 20" class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">شروع</label>
            <input type="datetime-local" name="starts_at" required value="{{ now('Asia/Tehran')->format('Y-m-d\TH:i') }}" class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">پایان</label>
            <input type="datetime-local" name="ends_at" required class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
        </div>

        <div class="md:col-span-2">
            <button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white font-bold px-6 py-3 rounded-xl transition">
                <i class="fas fa-plus ml-1"></i> ثبت حراج
            </button>
        </div>
    </form>

    @if (empty($sales))
        <div class="bg-white rounded-2xl shadow-lg p-10 text-center text-gray-400">هنوز حراجی ثبت نشده است.</div>
    @else
        <div class="grid gap-4">
            @foreach ($sales as $sale)
                <?php
                    $live = !empty($sale['is_active']) && $sale['starts_at'] <= $now && $sale['ends_at'] > $now;
                    $upcoming = !empty($sale['is_active']) && $sale['starts_at'] > $now;
                    $state = $live ? ['در حال برگزاری', 'bg-green-100 text-green-700'] :
                        ($upcoming ? ['زمان‌بندی‌شده', 'bg-blue-100 text-blue-700'] :
                        (empty($sale['is_active']) ? ['غیرفعال', 'bg-gray-100 text-gray-500'] : ['پایان‌یافته', 'bg-gray-100 text-gray-500']));
                    $off = $sale['discount_type'] === 'percent' ? ((int)$sale['discount_value'] . '٪') : (number_format((int)$sale['discount_value']) . ' تومان');
                    $announcementText = \App\Services\Shop\FlashSaleAdminService::announcementText($sale);
                ?>
                <div class="bg-white rounded-2xl shadow-lg p-5">
                    <div class="flex flex-wrap justify-between items-center gap-3 mb-2">
                        <div class="font-bold text-gray-800">{{ $sale['plan_title'] ?: ('پلن ' . $sale['plan_id']) }}</div>
                        <span class="px-3 py-1 rounded-full text-xs font-bold {{ $state[1] }}">{{ $state[0] }}</span>
                    </div>
                    <div class="text-xs text-gray-500 flex flex-wrap gap-4 mb-3">
                        <span>تخفیف: <b class="text-rose-600">{{ $off }}</b></span>
                        <span>شروع: <span class="font-mono">{{ \App\Services\Shop\FlashSaleAdminService::jalaliDate($sale['starts_at'], '/') }}</span> ({{ $sale['starts_at'] }})</span>
                        <span>پایان: <span class="font-mono">{{ \App\Services\Shop\FlashSaleAdminService::jalaliDate($sale['ends_at'], '/') }}</span> ({{ $sale['ends_at'] }})</span>
                        @if ($live)
                            <span>باقی‌مانده: <b>{{ \App\Services\Shop\FlashSaleAdminService::remainingText($sale['ends_at']) }}</b></span>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" class="inline">
                            @csrf
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="{{ $sale['id'] }}">
                            <input type="hidden" name="to" value="{{ empty($sale['is_active']) ? '1' : '' }}">
                            <button class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-xl text-sm font-bold">
                                {{ empty($sale['is_active']) ? 'فعال‌سازی' : 'غیرفعال‌سازی' }}
                            </button>
                        </form>
                        <a href="{{ route('admin.broadcast.show') }}?prefill={{ urlencode($announcementText) }}" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-700 px-4 py-2 rounded-xl text-sm font-bold">
                            <i class="fas fa-bullhorn ml-1"></i> اطلاع‌رسانی همگانی
                        </a>
                        <form method="POST" class="inline" onsubmit="return confirm('این حراج حذف شود؟');">
                            @csrf
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="{{ $sale['id'] }}">
                            <button class="bg-red-100 hover:bg-red-200 text-red-700 px-4 py-2 rounded-xl text-sm font-bold">حذف</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection