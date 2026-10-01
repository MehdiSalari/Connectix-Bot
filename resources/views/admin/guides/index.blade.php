@extends('layouts.admin', ['title' => 'مدیریت آموزش‌ها', 'active' => 'guides'])

@section('content')
    {{-- «نحوه استفاده» و پلتفرم‌ها دو چیز متفاوت‌اند: اولی توضیح کلی کار با
         سرویس است و دومی راهنمای هر اپلیکیشن. قبلاً هر دو در یک جدول بودند و
         «use» میان پلتفرم‌ها گم می‌شد. هر دو بخش در یک فرم‌اند تا یک دکمه‌ی
         ذخیره برای همه‌ی ردیف‌ها کافی باشد. --}}
    <form method="post" class="form-stack" action="{{ route('admin.guides.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="card">
            <h2>نحوه استفاده کلی از سرویس</h2>
            <p class="muted">توضیح عمومی «چطور از سرویس استفاده کنم» که ربات برای همه‌ی کاربران نشان می‌دهد. یک ویدیو یا یک لینک.</p>

            <table>
                <thead>
                    <tr>
                        <th>بخش</th>
                        <th>وضعیت فعلی</th>
                        <th>محتوا <span class="th-hint">ویدیو یا لینک</span></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @include('admin.guides._row', ['key' => 'use', 'entry' => $existing['use']])
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>آموزش‌های پلتفرم‌ها</h2>
            <p class="muted">برای هر پلتفرم یکی از دو حالت: ویدیوی MP4 (حداکثر ۱۰ مگابایت) یا لینک. با «حذف» همان ردیف، آموزش آن پلتفرم از ربات پاک می‌شود.</p>

            <table>
                <thead>
                    <tr>
                        <th>پلتفرم</th>
                        <th>وضعیت فعلی</th>
                        <th>محتوا <span class="th-hint">ویدیو یا لینک</span></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($platforms as $platform)
                        @if ($platform === 'use')
                            @continue
                        @endif
                        @include('admin.guides._row', ['key' => $platform, 'entry' => $existing[$platform]])
                    @endforeach
                </tbody>
            </table>

            <div style="margin-top:16px">
                <button type="submit">ذخیره آموزش‌ها</button>
            </div>
        </div>
    </form>

    {{-- فرم‌های حذف، بیرون از فرم ذخیره (فرم را نمی‌توان در فرم تودرتو کرد).
         هر دکمه‌ی «حذف» داخل جدول با صفت form= به همین‌ها وصل است. --}}
    <div class="guide-delete-forms" hidden>
        @foreach ($platforms as $platform)
            <form method="post" action="{{ route('admin.guides.destroy') }}"
                  id="guide-delete-{{ $platform }}"
                  data-confirm="آموزش «{{ $labels[$platform] ?? $platform }}» حذف می‌شود و ربات دیگر آن را نشان نمی‌دهد."
                  data-confirm-title="حذف آموزش"
                  data-confirm-accept="حذف کن">
                @csrf
                <input type="hidden" name="type" value="platform">
                <input type="hidden" name="name" value="{{ $platform }}">
            </form>
        @endforeach
    </div>

    <div class="card">
        <h2>آموزش اختصاصی جدید</h2>
        <p class="muted">عنوان به‌همراه ویدیو یا لینک. عنوان بدون محتوا پذیرفته نمی‌شود.</p>

        <form method="post" action="{{ route('admin.guides.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="row row-spaced">
                <div>
                    <label>عنوان</label>
                    <input type="text" name="custom_title" placeholder="مثلاً: اتصال با V2Box">
                </div>
                <div>
                    <label>ویدیو <span class="th-hint">یا</span></label>
                    <input type="file" name="custom_video" accept="video/mp4">
                </div>
                <div>
                    <label>لینک</label>
                    <input type="url" name="custom_link" placeholder="https://...">
                </div>
            </div>
            <div style="margin-top:16px">
                <button type="submit">افزودن</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>آموزش‌های اختصاصی موجود ({{ count($customItems) }})</h2>
        <table>
            <thead>
                <tr>
                    <th>عنوان</th>
                    <th>نوع</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($customItems as $item)
                    <tr>
                        <td>{{ $item['title'] }}</td>
                        <td>
                            @if ($item['type'] === 'video')
                                <span class="badge ok">ویدیو</span>
                            @else
                                <span class="badge">لینک</span>
                            @endif
                        </td>
                        <td class="cell-actions">
                            <form method="post" action="{{ route('admin.guides.destroy') }}" class="inline-form"
                                  data-confirm="آموزش «{{ $item['title'] }}» حذف می‌شود و ربات دیگر آن را به کاربر نشان نمی‌دهد."
                                  data-confirm-title="حذف آموزش"
                                  data-confirm-accept="حذف کن">
                                @csrf
                                <input type="hidden" name="type" value="custom">
                                <input type="hidden" name="name" value="{{ $item['title'] }}">
                                <button type="submit" class="btn danger">حذف</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">آموزش اختصاصی وجود ندارد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <script>
        (function () {
            /* هر ردیف یک حالت دارد: ویدیو یا لینک.
             *
             * فیلد انتخاب‌نشده هم مخفی می‌شود هم disabled تا در POST نیاید؛
             * بدون این اسکریپت هر دو فیلد دیده می‌شوند و قاعده‌ی سرور (اگر هر
             * دو آمد، ویدیو برنده است) پابرجاست. */
            document.querySelectorAll('[data-guide-pick]').forEach(function (wrap) {
                var video = wrap.querySelector('.guide-video');
                var link = wrap.querySelector('.guide-link');
                if (!video || !link) return;

                function apply() {
                    var checked = wrap.querySelector('input[data-guide-mode]:checked');
                    var mode = checked ? checked.getAttribute('data-guide-mode') : 'video';

                    video.disabled = mode !== 'video';
                    video.hidden = mode !== 'video';
                    link.disabled = mode !== 'link';
                    link.hidden = mode !== 'link';

                    // مقدار فیلدِ کنار رفته پاک می‌شود تا اگر چیزی از قبل
                    // در آن مانده بود به‌اشتباه با حالت جدید ارسال شود.
                    if (mode === 'video') {
                        link.value = '';
                    } else if (video.value) {
                        video.value = '';
                    }
                }

                wrap.addEventListener('change', apply);
                apply();
            });
        })();
    </script>
@endsection
