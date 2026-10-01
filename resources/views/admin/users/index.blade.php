@extends('layouts.admin', ['title' => 'کاربران', 'active' => 'users'])

@section('content')
    <div class="card">
        {{-- جستجو لحظه‌ای است: تایپ، بدون زدن Enter، فهرست را عوض می‌کند
             (اسکریپت پایین همین صفحه). سرور هم همین query را می‌خواند، پس
             بدون جاوااسکریپت فرم همچنان با ارسال عادی کار می‌کند و نتیجه‌ی
             مستقیم URL با ?search= همان است. --}}
        <form method="get" action="{{ route('admin.users.index') }}" class="row" data-live-search>
            <div>
                <label for="search">جستجو (شناسه گفتگو، نام یا تلگرام)</label>
                <input type="text" id="search" name="search" value="{{ $search }}"
                       placeholder="مثال: 123456789" autocomplete="off" spellcheck="false"
                       data-live-input>
            </div>
            <button type="submit" style="margin-left:0">جستجو</button>
        </form>
    </div>

    <div class="card" id="users-results">
        <table>
            <thead>
                <tr>
                    <th>عکس</th>
                    <th>نام</th>
                    <th>تلگرام</th>
                    <th>شناسه گفتگو</th>
                    <th>کیف پول</th>
                    <th>اکانت تست</th>
                    <th>تاریخ عضویت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    {{-- دو هدفِ جدا: کلیک روی خودِ سطر، پنجره‌ی خلاصه را باز
                         می‌کند (data-user-details روی <tr>) و تنها دکمه‌ی سطر،
                         «جزئیات»، به صفحه‌ی کامل می‌رود. پنجره برای نگاهِ سریع
                         است؛ صفحه برای جدول‌هایی که فقط آنجا هستند (اکانت‌ها،
                         همه‌ی تراکنش‌ها، همه‌ی سفارش‌ها).

                         کنترلِ داخل سطر فقط عکس است: آواتار کار خودش را
                         می‌کند (بزرگ‌نمایی)، چون شنونده‌ی partials/user-details
                         کنترل‌ها را نادیده می‌گیرد و فقط خودِ سطر را هدف
                         می‌گیرد. نام لینک نیست تا دو لینکِ هم‌مقصد (نام و
                         «جزئیات») سطر را شلوغ نکنند و کلیک روی نام هم همان
                         خلاصه را باز کند.

                         آواتار یک <button> واقعی است تا با Tab و Enter هم کار
                         کند، و برچسبش نام کاربر را می‌گوید (همان چیزی که
                         صفحه‌خوان می‌خواند). اگر عکسی نباشد فقط حرف اول
                         نام است و دکمه‌ای ساخته نمی‌شود. --}}
                    <tr data-user-details="{{ $user->chat_id }}"
                        data-user-label="{{ $user->name ?: 'کاربر '.$user->chat_id }}">
                        <td>
                            @if ($user->avatar)
                                <button type="button" class="avatar avatar-btn"
                                        data-photo="{{ $user->avatar }}"
                                        data-photo-name="{{ $user->name ?: 'کاربر '.$user->chat_id }}"
                                        aria-label="بزرگ‌نمایی عکس {{ $user->name ?: 'کاربر '.$user->chat_id }}">
                                    {{ mb_substr(trim((string) ($user->name ?? '')) !== '' ? (string) $user->name : (string) $user->chat_id, 0, 1) }}<img class="avatar-photo" src="{{ $user->avatar }}" alt="" loading="lazy" onerror="this.remove()">
                                </button>
                            @else
                                <span class="avatar">{{ mb_substr(trim((string) ($user->name ?? '')) !== '' ? (string) $user->name : (string) $user->chat_id, 0, 1) }}</span>
                            @endif
                        </td>
                        <td>
                            {{-- نام، متنِ معمولی است نه لینک: رفتن به صفحه‌ی
                                 کامل، کارِ دکمه‌ی «جزئیات» همین سطر است. --}}
                            {{ $user->name ?? '-' }}
                        </td>
                        <td class="ltr">{{ $user->telegram_id ?? '—' }}</td>
                        <td class="ltr">{{ $user->chat_id }}</td>
                        <td>{{ $user->wallet ? number_format($user->wallet->balanceAmount()) : '—' }}</td>
                        <td>{{ $user->hasUsedTest() ? 'بله' : 'خیر' }}</td>
                        <td>{{ $user->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="cell-actions">
                            {{-- تنها دکمه‌ی سطر: به صفحه‌ی کامل می‌رود. پنجره‌ی
                                 خلاصه را خودِ سطر (بالاتر) باز می‌کند؛ دو دکمه
                                 کنار هم فقط این دو هدف را در جدول شلوغ گم
                                 می‌کردند. --}}
                            <a class="btn ghost"
                               href="{{ route('admin.users.show', $user) }}">جزئیات</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">کاربری یافت نشد.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($users->hasPages())
            <div class="pager">
                {{ $users->links() }}
            </div>
        @endif
    </div>

    <script>
        (function () {
            /* جستجوی لحظه‌ای.
             *
             * به‌جای برگرداندن JSON و بازسازی سطر در مرورگر، همان صفحه‌ی
             * list دوباره گرفته می‌شود و فقط #users-results عوض می‌شود:
             * ستون‌ها، آواتار، کیف پول، لینک‌ها و data-user-details همان
             * چیزی می‌مانند که Blade می‌سازد و هیچ نسخه‌ی دومی از مارک‌اپ
             * سطر در جاوااسکریپت وجود ندارد که از هم بیفتد.
             *
             * token جواب‌های خارج از ترتیب را می‌پراند (کاربر دارد تایپ
             * می‌کند و پاسخِ تایپِ قبلی دیر می‌رسد) و replaceState آدرس را
             * بدون بارگذاری دوباره به‌روز نگه می‌دارد تا لینک قابل کپی باشد. */
            var form = document.querySelector('[data-live-search]');
            var input = document.querySelector('[data-live-input]');
            var results = document.getElementById('users-results');
            if (!form || !input || !results) return;

            var INDEX = @json(route('admin.users.index'));
            var token = 0;
            var timer = null;

            function hrefFor(term) {
                return INDEX + (term === '' ? '' : '?search=' + encodeURIComponent(term));
            }

            function run() {
                var term = input.value.trim();
                var mine = ++token;

                try { history.replaceState(null, '', hrefFor(term)); } catch (e) { /* file: امن نیست */ }

                results.classList.add('is-loading');

                fetch(hrefFor(term), {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
                }).then(function (response) {
                    return response.ok ? response.text() : null;
                }).then(function (html) {
                    if (mine !== token || html === null) return;
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var fresh = doc.getElementById('users-results');
                    // پاسخِ login (نشست تمام شده) یا صفحه‌ی خطای دیگر، این
                    // شناسه را ندارد؛ فهرست فعلی به جای پاک شدن می‌ماند.
                    if (fresh) results.innerHTML = fresh.innerHTML;
                }).catch(function () {
                    /* بی‌صدا: فهرست قبلی پابرجاست و Enter هنوز راهِ فرم است */
                }).then(function () {
                    if (mine === token) results.classList.remove('is-loading');
                });
            }

            function schedule() {
                clearTimeout(timer);
                timer = setTimeout(run, 250);
            }

            input.addEventListener('input', schedule);
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                clearTimeout(timer);
                run();
            });
        })();
    </script>
@endsection
