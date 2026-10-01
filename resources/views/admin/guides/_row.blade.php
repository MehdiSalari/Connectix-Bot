{{--
    یک ردیف از جدول آموزش‌های استاندارد (پلتفرم یا «نحوه استفاده»).

    ورودی‌ها: $key (کلید ذخیره‌شده، انگلیسی)، $entry (وضعیت فعلی)، $labels.

    «یا ویدیو یا لینک» به شکل انتخاب نوع (radio) نشان داده می‌شود، نه دو فیلد
    کنار هم که باید خودت بفهمی کدام را پر کنی. فیلد انتخاب‌نشده هم مخفی و هم
    disabled می‌شود تا ارسال نشود؛ بدون جاوااسکریپت هر دو فیلد دیده می‌شوند و
    رفتار قبلی (اگر هر دو پر شوند، ویدیو برنده است) حفظ می‌شود.

    دکمه‌ی حذف با صفت `form` به فرم حذف وصل است که بیرون از فرم ذخیره می‌نشیند:
    فرم‌ها را نمی‌توان در هم تودرتو کرد وگرنه مرورگر برچسب داخلی را نادیده می‌گیرد
    و فرم ذخیره خراب می‌شود.
--}}
@php
    $label = $labels[$key] ?? $key;
    // حالت اولیه: لینک ذخیره‌شده یعنی این ردیف الان لینک است، وگرنه ویدیو.
    $mode = $entry['txt'] !== null && $entry['mp4'] === null ? 'link' : 'video';
@endphp
<tr>
    <td>
        <strong>{{ $label }}</strong>
        <p class="muted hint" dir="ltr">{{ $key }}</p>
    </td>
    <td>
        @if ($entry['mp4'])
            <span class="badge ok">ویدیو ({{ number_format($entry['mp4']['size'] / 1024) }} کیلوبایت)</span>
        @elseif ($entry['txt'])
            <span class="badge ok">لینک ✓</span>
        @else
            <span class="badge no">خالی</span>
        @endif
    </td>
    <td>
        <div class="guide-pick" data-guide-pick>
            <div class="guide-mode">
                <label class="guide-mode-opt">
                    <input type="radio" name="guide_{{ $key }}_mode" value="video"
                           data-guide-mode="video" {{ $mode === 'video' ? 'checked' : '' }}>
                    ویدیو
                </label>
                <label class="guide-mode-opt">
                    <input type="radio" name="guide_{{ $key }}_mode" value="link"
                           data-guide-mode="link" {{ $mode === 'link' ? 'checked' : '' }}>
                    لینک
                </label>
            </div>

            <input class="guide-video" type="file" name="guide_{{ $key }}_video" accept="video/mp4"
                   title="ویدیوی MP4، حداکثر ۱۰ مگابایت">
            <input class="guide-link" type="url" name="guide_{{ $key }}_link" placeholder="https://..."
                   title="لینک جای ویدیوی فعلی را می‌گیرد">
        </div>
    </td>
    <td class="cell-actions">
        <button type="submit" form="guide-delete-{{ $key }}" class="btn danger">حذف</button>
    </td>
</tr>
