{{--
    بزرگ‌نمایی عکس کاربر.

    هر المنتی با `data-photo="<url>"` (و اختیاری `data-photo-name`) این لایه را
    باز می‌کند. عکس‌ها از CDN تلگرام می‌آیند، پس هیچ مقداری با innerHTML نوشته
    نمی‌شود؛ src و alt را با setAttribute می‌گذاریم.

    چرا لایه‌ی جدا و نه همان مودال جزئیات: عکسِ کاربر چیزی برای خواندن ندارد،
    پس پنجره‌ای که فقط عکس را نشان می‌دهد سبک‌تر است و روی موبایل تمام‌صفحه
    می‌شود - که همان چیزی است که انگشت می‌خواهد.
--}}
<div class="photo-lightbox" id="photo-lightbox" hidden role="dialog" aria-modal="true" aria-label="تصویر کاربر">
    <div class="photo-lightbox-layer" data-photo-close></div>

    <figure class="photo-lightbox-figure">
        <img id="photo-lightbox-img" alt="" />
        <figcaption id="photo-lightbox-cap"></figcaption>

        <button type="button" class="photo-lightbox-x" data-photo-close aria-label="بستن">✕</button>
    </figure>
</div>

<script>
    (function () {
        var box = document.getElementById('photo-lightbox');
        if (!box) return;

        var img = document.getElementById('photo-lightbox-img');
        var cap = document.getElementById('photo-lightbox-cap');
        var lastFocus = null;

        function open(url, name) {
            lastFocus = document.activeElement;

            img.src = url;
            img.alt = name ? 'عکس ' + name : 'عکس کاربر';
            cap.textContent = name || '';

            box.hidden = false;
            window.cxLayerOpen(box);

            var closeBtn = box.querySelector('.photo-lightbox-x');
            if (closeBtn) closeBtn.focus();
        }

        function close() {
            box.hidden = true;
            // Clearing src stops a half-downloaded image from being cached for
            // the next user whose photo is opened.
            img.removeAttribute('src');
            window.cxLayerClose(box);
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-photo]');
            if (trigger) {
                event.preventDefault();
                open(trigger.dataset.photo, trigger.dataset.photoName || '');
                return;
            }

            if (event.target.closest('[data-photo-close]')) close();
        });

        // The photo can sit on a real <button> (a row avatar) or on a <span>
        // with role="button" (the profile header, which is not itself a button).
        // Enter and Space are therefore handled here, so the span is not a
        // keyboard trap that only a mouse can open.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !box.hidden && window.cxIsTopLayer(box)) { close(); return; }

            if (event.key !== 'Enter' && event.key !== ' ') return;

            var trigger = event.target.closest && event.target.closest('[data-photo]');
            if (!trigger || trigger.tagName === 'BUTTON') return;

            event.preventDefault();
            open(trigger.dataset.photo, trigger.dataset.photoName || '');
        });
    })();
</script>
