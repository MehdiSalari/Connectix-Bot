<div class="alert warn">
    هر دو بخش زیر فقط رکورد <strong>اضافه</strong> می‌کنند. هیچ رکوردی حذف یا بازنویسی نمی‌شود، پس اگر
    نصب نیمه‌کاره بود، اجرای دوباره‌ی همین دکمه‌ها امن است و از همان‌جا ادامه می‌دهد.
</div>

<h3>انتقال از دیتابیس نصب قبلی</h3>
<p class="muted">
    اگر این سرور قبلاً نسخه قدیمی روی همین دیتابیس اجرا می‌شده، ردیف‌های آن را می‌آوریم.
    بدون تیک «تأیید»، فقط آزمایش اجرا می‌شود و چیزی نوشته نمی‌شود.
</p>

<form method="post" action="{{ route('setup.import') }}">
    @csrf
    <input type="hidden" name="action" value="legacy">

    <div class="grid">
        <div class="field">
            <label for="legacy_host">آدرس دیتابیس قبلی</label>
            <input type="text" id="legacy_host" name="legacy_host" value="{{ config('database.connections.legacy.host') }}"
                   placeholder="localhost">
        </div>
        <div class="field">
            <label for="legacy_port">پورت</label>
            <input type="text" id="legacy_port" name="legacy_port"
                   value="{{ config('database.connections.legacy.port') ?: '3306' }}" placeholder="3306">
        </div>
        <div class="field">
            <label for="legacy_database">نام دیتابیس قبلی</label>
            <input type="text" id="legacy_database" name="legacy_database"
                   value="{{ config('database.connections.legacy.database') }}">
        </div>
        <div class="field">
            <label for="legacy_username">نام کاربری</label>
            <input type="text" id="legacy_username" name="legacy_username"
                   value="{{ config('database.connections.legacy.username') }}" autocomplete="off">
        </div>
        <div class="field">
            <label for="legacy_password">رمز عبور</label>
            <input type="password" id="legacy_password" name="legacy_password" autocomplete="new-password">
        </div>
    </div>

    <div class="field">
        <label class="check">
            <input type="checkbox" name="confirm" value="1">
            <span>تأیید می‌کنم که این انتقال روی دیتابیس درست انجام شود (بدون این تیک، فقط آزمایش است)</span>
        </label>
    </div>

    <div class="actions">
        <button type="submit">انتقال اطلاعات نصب قبلی</button>
    </div>
</form>

<h3>خواندن مشتریان از پنل</h3>
<p class="muted">
    معادل گام آخر نسخه legacy: مشتریان و کیف پول‌ها از پنل فروشنده خوانده می‌شوند.
    هر بار پنج صفحه خوانده می‌شود تا درخواست طولانی نشود؛ با زمان‌بندی روزانه، بقیه هم خودکار کامل می‌شود.
</p>

<form method="post" action="{{ route('setup.import') }}">
    @csrf
    <div class="actions">
        <button type="submit" name="action" value="panel">خواندن از پنل</button>
        <a href="{{ route('setup.show', ['step' => $step->next()->value]) }}">
            <button type="button" class="secondary">رفتن به {{ $step->next()->label() }}</button>
        </a>
    </div>
</form>

@if (session('report'))
    <h3>نتیجه</h3>
    <table>
        <tr>
            <th>جدول</th>
            <th>خوانده</th>
            <th>نوشته</th>
            <th>رد شده</th>
            <th>ناموفق</th>
        </tr>
        @foreach (session('report') as $table => $counts)
            @if (is_array($counts) && isset($counts['read']))
                <tr>
                    <td><code>{{ $table }}</code></td>
                    <td>{{ $counts['read'] }}</td>
                    <td>{{ $counts['written'] }}</td>
                    <td>{{ $counts['skipped'] }}</td>
                    <td>{{ $counts['failed'] }}</td>
                </tr>
            @else
                <tr>
                    <td><code>{{ $table }}</code></td>
                    <td colspan="4" class="muted">
                        @foreach ($counts as $key => $value)
                            {{ $key }}: {{ is_scalar($value) ? $value : json_encode($value) }}@if (! $loop->last)، @endif
                        @endforeach
                    </td>
                </tr>
            @endif
        @endforeach
    </table>
@endif

<h3>وضعیت فعلی جدول‌ها</h3>
<table>
    <tr>
        <th style="width: 40%">جدول</th>
        <th>تعداد رکورد</th>
    </tr>
    @foreach ($stepData['counts'] as $table => $count)
        <tr>
            <td><code>{{ $table }}</code></td>
            <td>{{ number_format($count) }}</td>
        </tr>
    @endforeach
</table>
