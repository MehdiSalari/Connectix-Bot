<form method="POST" class="grid gap-3 md:grid-cols-2 ly-reward-form">
    @csrf
    <input type="hidden" name="action" value="save_reward">
    <input type="hidden" name="reward_id" value="{{ (int) ($r['id'] ?? 0) }}">
    <input type="text" name="title" required value="{{ $r['title'] ?? '' }}" placeholder="عنوان (مثلاً ۲۵٪ تخفیف)" class="border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
    <input type="text" name="description" value="{{ $r['description'] ?? '' }}" placeholder="توضیح کوتاه (اختیاری)" class="border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
    <select name="kind" class="ly-kind border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
        @foreach ($kinds as $k => $label)
            <option value="{{ $k }}" {{ ($r['kind'] ?? 'percent') === $k ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
    <input type="number" min="1" name="value" data-ly="value" value="{{ (int) ($r['value'] ?? 10) }}" placeholder="مقدار (درصد یا تومان)" class="border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
    <select name="plan_id" class="ly-plan border border-gray-300 rounded-xl px-3 py-2.5 text-sm md:col-span-2">
        <option value="">همهٔ پلن‌ها (فقط برای کد تخفیف)</option>
        @foreach ($plans as $p)
            <option value="{{ (string) $p['id'] }}" {{ (string) ($r['plan_id'] ?? '') === (string) $p['id'] ? 'selected' : '' }}>{{ (string) ($p['title'] ?? '') }} — {{ number_format($p['sell_price'] ?? 0) }} تومان</option>
        @endforeach
    </select>
    <div class="ly-codes-box md:col-span-2 hidden">
        <?php $ps = (int)($r['id'] ?? 0) ? \App\Services\Loyalty\LoyaltyAdminService::poolStats((int)$r['id']) : ['free' => 0, 'given' => 0]; ?>
        <label class="text-sm font-bold">لیست کدهای تخفیف (هر خط یک کد)
            <textarea name="codes_text" rows="5" dir="ltr" placeholder="OFF-1111&#10;OFF-2222&#10;OFF-3333" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono"></textarea>
        </label>
        <div class="text-xs text-gray-500 mt-1 leading-6">با هر بار «دریافت جایزه» یک کد از این لیست به کاربر داده می‌شود (در وب‌اپ نمایش داده می‌شود و در ربات هم ارسال می‌شود) و دیگر به کس دیگری داده نمی‌شود. بعداً هم می‌توانید کدهای بیشتری اضافه کنید؛ کدهای تکراری نادیده گرفته می‌شوند.
            @if ((int)($r['id'] ?? 0))
                <br><b>باقی‌مانده: {{ (int) $ps['free'] }}</b> · تحویل‌شده: {{ (int) $ps['given'] }}
            @endif
        </div>
    </div>
    <input type="number" min="1" name="cost" required value="{{ (int) ($r['cost'] ?? 100) }}" placeholder="هزینه (امتیاز)" class="border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
    <input type="number" min="0" name="stock" data-ly="stock" value="{{ $r['stock'] ?? '' }}" placeholder="ظرفیت کل (خالی = نامحدود)" class="border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
    <input type="number" min="0" name="per_user_month" value="{{ $r['per_user_month'] ?? '' }}" placeholder="سقف ماهانهٔ هر کاربر (خالی = نامحدود)" class="border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
    <input type="number" min="1" name="valid_days" value="{{ $r['valid_days'] ?? '' }}" placeholder="اعتبار کد (روز) — خالی = پیش‌فرض" class="border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
    <label class="text-xs text-gray-500">شروع (اختیاری)<input type="datetime-local" name="starts_at" value="{{ $r['starts_at'] ? str_replace(' ', 'T', substr($r['starts_at'], 0, 16)) : '' }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2 text-sm"></label>
    <label class="text-xs text-gray-500">پایان (اختیاری)<input type="datetime-local" name="ends_at" value="{{ $r['ends_at'] ? str_replace(' ', 'T', substr($r['ends_at'], 0, 16)) : '' }}" class="mt-1 w-full border border-gray-300 rounded-xl px-3 py-2 text-sm"></label>
    <input type="number" name="sort_order" value="{{ (int) ($r['sort_order'] ?? 0) }}" placeholder="ترتیب نمایش" class="border border-gray-300 rounded-xl px-3 py-2.5 text-sm font-mono">
    <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="is_active" value="1" {{ ((int)($r['id'] ?? 0) === 0 || !empty($r['is_active'])) ? 'checked' : '' }}> فعال</label>
    <div class="md:col-span-2"><button class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl transition">{{ (int)($r['id'] ?? 0) ? 'ذخیرهٔ تغییرات' : 'ساخت جایزه' }}</button></div>
</form>