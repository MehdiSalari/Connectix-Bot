@extends('layouts.admin')

@section('title', 'سیستم امتیازدهی')

@section('content')
<div class="container mx-auto px-4 py-6 max-w-5xl">
    @if ($actionMsg)
        <div class="p-4 rounded-xl mb-6 font-bold {{ $actionMsg['type'] === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white' }}">
            {{ $actionMsg['text'] }}
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-lg p-5 mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <div class="font-black text-lg"><i class="fas fa-star ml-2 text-amber-500"></i> سیستم امتیازدهی</div>
            <div class="text-xs text-gray-500 mt-1">کاربر از خرید امتیاز می‌گیرد و فقط داخل وب‌اپ آن را به جایزه تبدیل می‌کند.</div>
        </div>
        <span class="px-4 py-1.5 rounded-full text-sm font-bold {{ $enabled ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
            {{ $enabled ? 'فعال' : 'غیرفعال' }}
        </span>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        @foreach ([['امتیاز صادرشده', $stats['issued']], ['امتیاز مصرف‌شده', $stats['spent']], ['امتیاز فعال کاربران', $stats['alive']], ['کاربران دارای امتیاز', $stats['users']], ['جایزه‌های گرفته‌شده', $stats['redemptions']], ['کدهای استفاده‌شده', $stats['codes_used']]] as [$l, $v])
            <div class="bg-white rounded-2xl shadow p-4 text-center">
                <div class="text-2xl font-black text-indigo-600">{{ number_format((int) $v) }}</div>
                <div class="text-xs text-gray-500 mt-1">{{ $l }}</div>
            </div>
        @endforeach
    </div>

    <!-- Settings -->
    <form method="POST" class="bg-white rounded-2xl shadow-lg p-6 mb-6 grid gap-4">
        @csrf
        <input type="hidden" name="action" value="save_settings">

        <h2 class="font-black text-lg"><i class="fas fa-sliders ml-2"></i> تنظیمات</h2>

        <label class="flex items-center gap-3 p-4 rounded-xl bg-amber-50 border border-amber-200 font-bold">
            <input type="checkbox" name="enabled" value="1" {{ $enabled ? 'checked' : '' }} class="w-5 h-5">
            سیستم امتیازدهی فعال باشد
            <span class="text-xs font-normal text-gray-500">(خاموش = امتیاز داده نمی‌شود، پیام ربات ارسال نمی‌شود و بخش امتیاز در وب‌اپ بسته است؛ امتیازهای قبلی حفظ می‌شوند)</span>
        </label>

        <div class="grid md:grid-cols-3 gap-4">
            <label class="text-sm font-bold">هر چند تومان خرید = ۱ امتیاز
                <input type="number" min="1" name="rate_toman" value="{{ (int) $settings['rate_toman'] }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 font-mono">
                <span class="block text-xs font-normal text-gray-400 mt-1">مثلاً ۱۰۰۰ → خرید ۱۵۰,۰۰۰ تومانی = ۱۵۰ امتیاز</span>
            </label>
            <label class="text-sm font-bold">هدیهٔ اولین خرید (امتیاز)
                <input type="number" min="0" name="first_bonus" value="{{ (int) $settings['first_bonus'] }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 font-mono">
            </label>
            <label class="text-sm font-bold">هدیهٔ هر تمدید (امتیاز)
                <input type="number" min="0" name="renew_bonus" value="{{ (int) $settings['renew_bonus'] }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 font-mono">
            </label>
            <label class="text-sm font-bold">اعتبار امتیاز (ماه) — ۰ = بدون انقضا
                <input type="number" min="0" name="expiry_months" value="{{ (int) $settings['expiry_months'] }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 font-mono">
            </label>
            <label class="text-sm font-bold">اعتبار پیش‌فرض کد جایزه (روز)
                <input type="number" min="1" name="code_valid_days" value="{{ (int) $settings['code_valid_days'] }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 font-mono">
            </label>
        </div>

        <div class="grid md:grid-cols-3 gap-4 items-end">
            <label class="flex items-center gap-2 text-sm font-bold">
                <input type="checkbox" name="daily_enabled" value="1" {{ $settings['daily_enabled'] === '1' ? 'checked' : '' }}> امتیاز ورود روزانه در وب‌اپ
            </label>
            <label class="text-sm font-bold">امتیاز هر روز
                <input type="number" min="0" name="daily_points" value="{{ (int) $settings['daily_points'] }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 font-mono">
            </label>
            <label class="text-sm font-bold">سقف ماهانه از ورود روزانه
                <input type="number" min="0" name="daily_month_cap" value="{{ (int) $settings['daily_month_cap'] }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 font-mono">
            </label>
        </div>

        <div class="grid md:grid-cols-2 gap-4">
            <label class="flex items-center gap-2 text-sm font-bold">
                <input type="checkbox" name="notify_bot" value="1" {{ $settings['notify_bot'] === '1' ? 'checked' : '' }}> بعد از خرید در ربات به کاربر بگو چند امتیاز گرفته
            </label>
            <label class="flex items-center gap-2 text-sm font-bold">
                <input type="checkbox" name="exclude_resellers" value="1" {{ $settings['exclude_resellers'] === '1' ? 'checked' : '' }}> نمایندگان امتیاز نگیرند
            </label>
        </div>

        <div>
            <label class="text-sm font-bold">آیدی ادمین برای دریافت جایزهٔ دستی
                <input type="text" name="admin_contact" dir="ltr" value="{{ $settings['admin_contact'] }}" placeholder="@admin" class="mt-1 w-full md:w-96 border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
            </label>
            <div class="text-xs text-gray-500 mt-1">فقط برای جایزه‌های نوع «دستی»؛ همراه کد ۸ کاراکتری به کاربر نشان داده می‌شود.</div>
        </div>

        <div>
            <label class="text-sm font-bold">نام دکمهٔ وب‌اپ در پیام ربات
                <input type="text" name="webapp_label" value="{{ $settings['webapp_label'] }}" placeholder="خالی = خودکار" class="mt-1 w-full md:w-96 border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
            </label>
            <div class="text-xs text-gray-500 mt-1">اگر خالی بماند، همیشه از همان اسمی که برای دکمهٔ وب‌اپ در «دکمه‌های صفحه اصلی» گذاشته‌ید استفاده می‌شود.</div>
        </div>

        <div><button class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl transition">ذخیرهٔ تنظیمات</button></div>
    </form>

    <!-- Rewards -->
    <section class="bg-white rounded-2xl shadow-lg p-6 mb-6">
        <h2 class="font-black text-lg mb-4"><i class="fas fa-gift ml-2"></i> جایزه‌ها</h2>
        <div class="grid gap-3 mb-5">
            @foreach ($rewards as $r)
                <?php
                    $label = $kinds[$r['kind']] ?? $r['kind'];
                    $val = $r['kind'] === 'percent' ? ((int)$r['value'] . '٪') :
                        ($r['kind'] === 'fixed' ? number_format((int)$r['value']) . ' تومان' :
                        ($r['kind'] === 'manual' ? 'تحویل دستی' :
                        ($r['kind'] === 'code_list' ? ('باقی‌مانده: ' . \App\Services\Loyalty\LoyaltyAdminService::poolStats((int)$r['id'])['free'] . ' کد') :
                        ($r['plan_title'] ?: 'پلن'))));
                ?>
                <details class="border border-gray-200 rounded-xl">
                    <summary class="p-4 cursor-pointer flex flex-wrap items-center justify-between gap-2">
                        <span class="font-bold">{{ $r['title'] }} <span class="text-xs font-normal text-gray-500">— {{ $label }} · {{ $val }}</span></span>
                        <span class="flex items-center gap-2 text-xs">
                            <span class="px-3 py-1 rounded-full bg-amber-100 text-amber-700 font-bold">{{ (int) $r['cost'] }} امتیاز</span>
                            <span class="text-gray-500">گرفته‌شده: {{ (int) $r['redeemed_count'] }}{{ $r['stock'] !== null ? ' از ' . (int) $r['stock'] : '' }}</span>
                            <span class="px-3 py-1 rounded-full font-bold {{ $r['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">{{ $r['is_active'] ? 'فعال' : 'غیرفعال' }}</span>
                        </span>
                    </summary>
                    <div class="p-4 pt-0">
                        @include('admin.loyalty._reward_form', ['r' => $r, 'plans' => $plans, 'kinds' => $kinds])
                        <form method="POST" class="mt-3" onsubmit="return confirm('این جایزه حذف شود؟');">
                            @csrf
                            <input type="hidden" name="action" value="delete_reward">
                            <input type="hidden" name="reward_id" value="{{ (int) $r['id'] }}">
                            <button class="bg-red-100 hover:bg-red-200 text-red-700 px-4 py-2 rounded-xl text-sm font-bold">حذف جایزه</button>
                        </form>
                    </div>
                </details>
            @endforeach
            @if (empty($rewards))
                <div class="text-center text-gray-400 py-6">هنوز جایزه‌ای ساخته نشده است.</div>
            @endif
        </div>
        <details class="border-2 border-dashed border-indigo-200 rounded-xl" {{ empty($rewards) ? 'open' : '' }}>
            <summary class="p-4 cursor-pointer font-bold text-indigo-600"><i class="fas fa-plus ml-1"></i> جایزهٔ جدید</summary>
            <div class="p-4 pt-0">
                @include('admin.loyalty._reward_form', ['r' => [], 'plans' => $plans, 'kinds' => $kinds])
            </div>
        </details>
        <div class="text-xs text-gray-500 mt-3 leading-6">کد جایزه یک‌بارمصرف و مخصوص همان کاربر است و در همان مرحلهٔ «کد تخفیف» ربات وارد می‌شود. «پلن رایگان» سفارش را بدون پرداخت ثبت می‌کند. «کد تخفیف از لیست»: خودتان لیست کدها را می‌گذارید و با هر دریافت یک کد به کاربر داده می‌شود. «پلن رایگان خودکار» کد نمی‌دهد: به‌محض گرفتن جایزه در وب‌اپ، ربات خودش پلن انتخاب‌شده را به‌صورت اکانت جدید می‌سازد و برای کاربر می‌فرستد (اگر ساخت شکست بخورد، امتیاز برمی‌گردد).</div>
    </section>

    <!-- Reward codes (LOY-) -->
    <section class="bg-white rounded-2xl shadow-lg p-6 mb-6">
        <h2 class="font-black text-lg mb-2"><i class="fas fa-ticket-alt ml-2"></i> کد تخفیف امتیازی (LOY-)</h2>
        <div class="text-xs text-gray-500 mb-4 leading-6">برای هر جایزه میتوانید کد ۸ کاراکتری (LOY-XXXXXXXX) تولید کنید. این کدها به کاربر داده میشوند تا در مرحلهٔ «کد تخفیف» ربات وارد کند و جایزه را بگیرد؛ هر کد یکبار قابل استفاده است.</div>

        <form method="POST" class="flex flex-wrap gap-2 mb-5 items-end p-4 rounded-xl bg-gray-50 border border-gray-200">
            @csrf
            <input type="hidden" name="action" value="generate_codes">
            <label class="text-sm font-bold">جایزه
                <select name="reward_id" required class="mt-1 block w-full md:w-72 border border-gray-300 rounded-xl px-3 py-2.5 text-sm bg-white">
                    @foreach ($rewards as $r)
                        <option value="{{ (int) $r['id'] }}">{{ $r['title'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-bold">تعداد
                <input type="number" name="count" min="1" max="500" value="1" class="mt-1 block w-28 border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
            </label>
            <button class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-2.5 rounded-xl transition">تولید کد</button>
            @if (empty($rewards))
                <span class="text-xs text-red-500">ابتدا یک جایزه بسازید.</span>
            @endif
        </form>

        @php
            $codesByReward = [];
            foreach ($rewards as $rr) {
                $codesByReward[(int) $rr['id']] = \App\Services\Loyalty\LoyaltyAdminService::codesForReward((int) $rr['id']);
            }
        @endphp
        @foreach ($rewards as $rr)
            @php $cs = $codesByReward[(int) $rr['id']]; @endphp
            <div class="mb-4">
                <div class="font-bold text-sm mb-2">{{ $rr['title'] }} <span class="text-xs text-gray-400 font-normal">({{ count($cs) }} کد)</span></div>
                @if (empty($cs))
                    <div class="text-xs text-gray-400">کدی برای این جایزه صادر نشده است.</div>
                @else
                    <div class="overflow-x-auto"><table class="w-full text-sm text-right">
                        <thead class="bg-gray-50 text-gray-600"><tr><th class="p-2">کد</th><th class="p-2">وضعیت</th><th class="p-2">تحویلشده به</th><th class="p-2">تاریخ صدور</th></tr></thead>
                        <tbody>
                            @foreach ($cs as $c)
                                <tr class="border-t">
                                    <td class="p-2 font-mono font-bold" dir="ltr">{{ $c['code'] }}</td>
                                    <td class="p-2">
                                        @if (empty($c['assigned_to']))
                                            <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-gray-100 text-gray-500">آزاد</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-700">تحویلشده</span>
                                        @endif
                                    </td>
                                    <td class="p-2 font-mono text-xs">{{ $c['assigned_to'] ?: '—' }}</td>
                                    <td class="p-2 text-xs font-mono">{{ \App\Services\Shop\FlashSaleAdminService::jalaliDate($c['created_at'], '/') }}</td>
                                </tr>
                            @endforeach
                        </tbody></table></div>
                @endif
            </div>
        @endforeach
    </section>

    <!-- Manual rewards -->
    <section class="bg-white rounded-2xl shadow-lg p-6 mb-6">
        <h2 class="font-black text-lg mb-1"><i class="fas fa-hand-holding-heart ml-2"></i> جایزه‌های دستی
            @if ($manualPending > 0)
                <span class="text-xs bg-amber-100 text-amber-700 rounded-full px-2 py-0.5 mr-2">{{ (int) $manualPending }} در انتظار تحویل</span>
            @endif
        </h2>
        <div class="text-xs text-gray-500 mb-3">وقتی کاربر کدش را فرستاد و جایزه را تحویل دادید، روی «پرداخت شد» بزنید.</div>
        <form method="GET" class="flex flex-wrap gap-2 mb-4">
            <input type="text" name="mcode" dir="ltr" value="{{ $manualSearch }}" placeholder="جستجو با کد ۸ کاراکتری یا آیدی کاربر" class="flex-1 min-w-[200px] border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
            <button class="bg-gray-800 text-white font-bold px-5 py-2.5 rounded-xl">جستجو</button>
        </form>
        @if (empty($manualList))
            <div class="text-sm text-gray-400">موردی نیست.</div>
        @else
            <div class="overflow-x-auto"><table class="w-full text-sm text-right">
                <thead class="bg-gray-50 text-gray-600"><tr><th class="p-2">تاریخ</th><th class="p-2">کاربر</th><th class="p-2">جایزه</th><th class="p-2">کد</th><th class="p-2">وضعیت</th></tr></thead>
                <tbody>
                    @foreach ($manualList as $m)
                        <tr class="border-t">
                            <td class="p-2 text-xs font-mono">{{ \App\Services\Shop\FlashSaleAdminService::jalaliDate($m['created_at'], '/') }}</td>
                            <td class="p-2"><a class="text-indigo-600" href="?user={{ urlencode((string) $m['chat_id']) }}">{{ (string) ($m['user_name'] ?: $m['chat_id']) }}</a>
                                <div class="text-xs text-gray-400 font-mono">{{ (string) $m['chat_id'] }}{{ !empty($m['user_tg']) ? ' @' . ltrim((string) $m['user_tg'], '@') : '' }}</div></td>
                            <td class="p-2">{{ (string) $m['reward_title'] }} <span class="text-xs text-gray-400">({{ (int) $m['points'] }} امتیاز)</span></td>
                            <td class="p-2 font-mono font-bold" dir="ltr">{{ (string) $m['code'] }}</td>
                            <td class="p-2">
                                @if (empty($m['used_at']))
                                    <form method="POST" onsubmit="return confirm('جایزه به کاربر تحویل داده شد؟');">
                                        @csrf
                                        <input type="hidden" name="action" value="manual_paid">
                                        <input type="hidden" name="redemption_id" value="{{ (int) $m['id'] }}">
                                        <button class="bg-green-600 hover:bg-green-700 text-white font-bold text-xs px-3 py-1.5 rounded-lg">پرداخت شد</button>
                                    </form>
                                @else
                                    <span class="text-green-600 font-bold text-xs">✅ پرداخت شد</span>
                                    <div class="text-xs text-gray-400 font-mono">{{ \App\Services\Shop\FlashSaleAdminService::jalaliDate($m['used_at'], '/') }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody></table></div>
        @endif
    </section>

    <!-- Users -->
    <section class="bg-white rounded-2xl shadow-lg p-6 mb-6">
        <h2 class="font-black text-lg mb-4"><i class="fas fa-users ml-2"></i> امتیاز کاربران</h2>
        <form method="GET" class="flex flex-wrap gap-2 mb-4">
            <input type="text" name="user" value="{{ $lookupChat }}" placeholder="آیدی عددی کاربر" class="flex-1 min-w-[200px] border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
            <button class="bg-gray-800 text-white font-bold px-5 py-2.5 rounded-xl">جستجو</button>
        </form>
        @if ($lookupChat !== '')
            @if (!$lookupUser)
                <div class="text-red-600 text-sm mb-4">کاربری با این آیدی پیدا نشد.</div>
            @else
                <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 mb-4">
                    <div class="font-bold">{{ (string) ($lookupUser['name'] ?? '—') }} <span class="font-mono text-xs text-gray-500">({{ $lookupChat }})</span></div>
                    <div class="text-2xl font-black text-amber-600 mt-1">{{ number_format(\App\Services\Loyalty\LoyaltyAdminService::balance($lookupChat)) }} امتیاز</div>
                </div>
                <form method="POST" class="grid gap-2 md:grid-cols-6 mb-4 p-4 rounded-xl border border-gray-200" onsubmit="return lyAdjustConfirm(this)">
                    @csrf
                    <input type="hidden" name="action" value="adjust">
                    <input type="hidden" name="chat_id" value="{{ $lookupChat }}">
                    <select name="mode" class="md:col-span-1 border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-bold">
                        <option value="deduct">➖ کسر امتیاز</option>
                        <option value="add">➕ افزودن امتیاز</option>
                    </select>
                    <input type="number" name="delta" required min="1" placeholder="مقدار (مثلاً 20)" class="md:col-span-1 border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono" dir="ltr">
                    <input type="text" name="note" maxlength="200" placeholder="دلیل (مثلاً: سوءاستفاده از کد تخفیف)" class="md:col-span-4 border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
                    <label class="md:col-span-5 flex items-center gap-2 text-sm font-semibold text-gray-700 cursor-pointer">
                        <input type="checkbox" name="notify_reason" value="1" class="w-4 h-4">
                        دلیل برای کاربر هم نمایش داده شود (پیام تلگرام + تاریخچهٔ وب‌اپ)
                    </label>
                    <button class="md:col-span-1 bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-2.5 rounded-xl">ثبت</button>
                    <div class="md:col-span-6 text-xs text-gray-500 leading-6">هنگام «کسر»، همیشه یک پیام برای کاربر ارسال می‌شود که مقدار دقیق امتیاز کسرشده و موجودی جدید را می‌گوید؛ دلیل فقط در صورت فعال‌بودن تیک بالا به کاربر نشان داده می‌شود. دلیل همیشه برای شما در تاریخچهٔ زیر ثبت می‌ماند.</div>
                </form>
                <div class="overflow-x-auto"><table class="w-full text-sm text-right">
                    <thead class="bg-gray-50 text-gray-600"><tr><th class="p-2">تاریخ</th><th class="p-2">دلیل</th><th class="p-2">مقدار</th></tr></thead>
                    <tbody>
                        @foreach (\App\Services\Loyalty\LoyaltyAdminService::history($lookupChat, 30) as $h)
                            <tr class="border-t"><td class="p-2 text-xs font-mono">{{ \App\Services\Shop\FlashSaleAdminService::jalaliDate($h['created_at'], '/') }}</td><td class="p-2">{{ \App\Services\Loyalty\LoyaltyAdminService::typeLabel((string) $h['type']) }} <span class="text-xs text-gray-400">{{ (string) $h['note'] }}</span></td><td class="p-2 font-bold font-mono {{ (int) $h['amount'] >= 0 ? 'text-green-600' : 'text-red-600' }}" dir="ltr">{{ (int) $h['amount'] > 0 ? '+' : '' }}{{ (int) $h['amount'] }}</td></tr>
                        @endforeach
                    </tbody></table></div>
            @endif
        @endif

        @if (!empty($top))
            <div class="font-bold text-sm mt-5 mb-2">بیشترین امتیاز</div>
            <div class="overflow-x-auto"><table class="w-full text-sm text-right"><tbody>
                @foreach ($top as $t)
                    <tr class="border-t"><td class="p-2"><a class="text-indigo-600" href="?user={{ urlencode((string) $t['chat_id']) }}">{{ (string) ($t['name'] ?: $t['chat_id']) }}</a> <span class="text-xs text-gray-400 font-mono">{{ (string) $t['chat_id'] }}</span></td><td class="p-2 font-bold text-amber-600 font-mono">{{ number_format((int) $t['bal']) }}</td></tr>
                @endforeach
            </tbody></table></div>
        @endif
    </section>
</div>

<script>
function lyAdjustConfirm(f){
    if (f.mode.value !== 'deduct') return true;
    var msg = 'کسر ' + f.delta.value + ' امتیاز از این کاربر؟\n' + (f.notify_reason.checked ? 'دلیل برای کاربر ارسال می‌شود.' : 'دلیل برای کاربر ارسال نمی‌شود (فقط مقدار کسرشده).');
    return confirm(msg);
}
(function(){
    document.querySelectorAll('.ly-reward-form').forEach(function(f){
        var kind = f.querySelector('.ly-kind'), sel = f.querySelector('.ly-plan'), val = f.querySelector('[data-ly="value"]');
        var box = f.querySelector('.ly-codes-box'), stock = f.querySelector('[data-ly="stock"]');
        function refresh(){
            var k = kind.value;
            var planKind = (k === 'free_plan' || k === 'auto_plan');
            var isManual = (k === 'manual');
            var isList = (k === 'code_list');
            val.style.display = (planKind || isList || isManual) ? 'none' : '';
            sel.style.display = (isList || isManual) ? 'none' : '';
            stock.style.display = isList ? 'none' : '';
            box.classList.toggle('hidden', !isList);
            sel.required = planKind;
            sel.options[0].textContent = planKind ? 'یک پلن انتخاب کنید' : 'همهٔ پلن‌ها (فقط برای کد تخفیف)';
        }
        kind.addEventListener('change', refresh);
        refresh();
    });
})();
</script>
@endsection