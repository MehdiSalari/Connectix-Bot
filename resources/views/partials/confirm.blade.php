{{--
    تایید عملیات، به‌جای confirm() مرورگر.

    هر فرمی با `data-confirm="متن تایید"` (یا `data-confirm-title`) قبل از ارسال
    همین دیالوگ را نشان می‌دهد. confirm() مرورگر ظاهر صفحه را ندارد، متن را با
    فونت سیستم نشان می‌دهد و روی موبایل تمام‌صفحه می‌شود - برای عملیاتی که
    اکانت کاربر را از پنل Connectix پاک می‌کند، این متن باید خوانا و با
    ظاهر پنل یکی باشد.

    الگو: data-confirm روی فرم. فرم submit نمی‌شود تا وقتی کاربر تایید نکند، و
    دکمه‌ی تایید خودش فرم را واقعاً submit می‌کند (پس CSRF و متد DELETE سر جایشان
    است و هیچ فرمی در صفحه‌ی اصلی دستکاری نمی‌شود).
--}}
<div class="modal" id="confirm-modal" hidden role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-text">
    <div class="modal-layer" data-confirm-close></div>

    <div class="modal-card sm">
        <header class="modal-head">
            <div class="identity">
                <span class="confirm-glyph" id="confirm-glyph" aria-hidden="true">!</span>
                <div>
                    <h3 id="confirm-title">تایید عملیات</h3>
                    <p class="sub" id="confirm-text">آیا مطمئن هستید؟</p>
                </div>
            </div>
        </header>

        <footer class="modal-foot">
            <button type="button" class="btn ghost" data-confirm-close>انصراف</button>
            <button type="button" class="btn danger" id="confirm-accept">تایید</button>
        </footer>
    </div>
</div>

<script>
    (function () {
        var modal = document.getElementById('confirm-modal');
        if (!modal) return;

        var title = document.getElementById('confirm-title');
        var text = document.getElementById('confirm-text');
        var glyph = document.getElementById('confirm-glyph');
        var accept = document.getElementById('confirm-accept');

        var pending = null;      // فرمی که باید واقعاً submit شود
        var confirmed = null;    // تنها فرمی که بدون پرسش رد می‌شود
        var lastFocus = null;

        function ask(form) {
            pending = form;
            lastFocus = document.activeElement;

            text.textContent = form.dataset.confirm || 'آیا مطمئن هستید؟';
            title.textContent = form.dataset.confirmTitle || 'تایید عملیات';

            // An irreversible action gets the warning glyph; a softer one does not.
            var danger = form.dataset.confirmTone !== 'soft';
            glyph.textContent = danger ? '!' : '?';
            glyph.className = 'confirm-glyph' + (danger ? ' danger' : '');
            accept.className = danger ? 'btn danger' : 'btn';
            accept.textContent = form.dataset.confirmAccept || (danger ? 'حذف کن' : 'تایید');

            modal.hidden = false;
            window.cxLayerOpen(modal);
            accept.focus();
        }

        function close() {
            pending = null;
            modal.hidden = true;
            window.cxLayerClose(modal);
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }

        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (!form.dataset || !form.dataset.confirm) return;

            // The submission the accept button just released. requestSubmit
            // fires this event again; without the flag the dialog would ask
            // about its own answer and the form would never actually go out.
            if (form === confirmed) {
                confirmed = null;
                return;
            }

            event.preventDefault();
            ask(form);
        });

        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-confirm-close]')) { close(); return; }

            if (event.target === accept && pending) {
                var form = pending;
                close();

                if (form.requestSubmit) {
                    // requestSubmit, not submit: it fires validation and the
                    // submit event again. The flag is armed before the call
                    // and cleared after it, because the event fires
                    // synchronously - so only that one submission passes.
                    confirmed = form;
                    form.requestSubmit();
                    confirmed = null;
                } else {
                    // No submit event at all, so no flag is needed.
                    form.submit();
                }
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden && window.cxIsTopLayer(modal)) close();
        });
    })();
</script>
