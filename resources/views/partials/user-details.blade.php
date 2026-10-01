{{--
    جزئیات کاربر به شکل مودال.

    هر دکمه‌ای با `data-user-details="<chat id>"` این مودال را باز می‌کند (آواتار
    هر ردیف در لیست کاربران همین کار را می‌کند). همه‌ی مقادیر با textContent
    ساخته می‌شوند نه innerHTML، چون نام و یوزرنیم از دیتابیس می‌آیند.
--}}
<div class="modal" id="user-details-modal" hidden role="dialog" aria-modal="true" aria-labelledby="user-details-title">
    <div class="modal-layer" data-user-modal-close></div>

    <div class="modal-card">
        <header class="modal-head">
            <div class="identity">
                <span class="avatar lg" id="user-details-avatar" aria-hidden="true">؟</span>
                <div>
                    <h3 id="user-details-title">جزئیات کاربر</h3>
                    <p class="sub" id="user-details-sub">در حال دریافت اطلاعات…</p>
                </div>
            </div>

            <div class="tools">
                <button type="button" class="icon-btn" data-user-modal-close aria-label="بستن">✕</button>
            </div>
        </header>

        <div class="modal-body" id="user-details-body"></div>

        <footer class="modal-foot">
            <a class="btn ghost grow" id="user-details-profile" href="#">پروفایل کامل</a>
            <button type="button" class="btn ghost" data-user-modal-close>بستن</button>
        </footer>
    </div>
</div>

<script>
    (function () {
        var ENDPOINT = @json(route('admin.users.details', ':id'));
        // جایگزینِ امن وقتی پاسخِ جزئیات profile_url نداشته باشد: همان
        // جستجوی شناسه در لیست، که قدیمی‌ترین رفتار این دکمه بود.
        var INDEX = @json(route('admin.users.index'));
        var modal = document.getElementById('user-details-modal');
        if (!modal) return;

        var body = document.getElementById('user-details-body');
        var title = document.getElementById('user-details-title');
        var sub = document.getElementById('user-details-sub');
        var avatar = document.getElementById('user-details-avatar');
        var profile = document.getElementById('user-details-profile');

        var openId = null;
        var token = 0;
        var lastFocus = null;

        var SKELETON =
            '<div class="skeleton" aria-hidden="true">' +
                '<div class="line w-40"></div>' +
                '<div class="blocks">' +
                    '<div class="line"></div><div class="line"></div><div class="line"></div>' +
                '</div>' +
                '<div class="line w-70"></div><div class="line w-90"></div>' +
            '</div>';

        function el(tag, className, text) {
            var node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined && text !== null) node.textContent = String(text);
            return node;
        }

        function copyButton(value) {
            var btn = el('button', 'mini-btn', 'کپی');
            btn.type = 'button';
            btn.addEventListener('click', function () {
                var text = String(value);
                var done = function () {
                    btn.textContent = 'کپی شد ✓';
                    btn.classList.add('done');
                    setTimeout(function () {
                        btn.textContent = 'کپی';
                        btn.classList.remove('done');
                    }, 1500);
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, function () { fallback(text, done); });
                } else {
                    fallback(text, done);
                }
            });
            return btn;
        }

        function fallback(text, done) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', 'readonly');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); done(); } catch (e) { /* بی‌صدا رد می‌شود */ }
            area.remove();
        }

        function cell(label, value, options) {
            options = options || {};

            var wrap = el('div', 'cell');
            var key = el('div', 'k');
            key.appendChild(el('span', null, label));
            if (options.copy && value) key.appendChild(copyButton(value));

            var val = el('div', 'v' + (options.mono ? ' ltr' : ''));
            var empty = value === null || value === undefined || value === '';

            if (empty) {
                val.classList.add('empty');
                val.textContent = '—';
            } else if (options.link) {
                var a = el('a', null, String(value));
                a.href = options.link;
                a.target = '_blank';
                a.rel = 'noopener';
                a.style.color = 'var(--accent-text)';
                val.appendChild(a);
            } else {
                val.textContent = String(value);
            }

            wrap.appendChild(key);
            wrap.appendChild(val);
            return wrap;
        }

        function section(heading, content) {
            var s = el('section', 'modal-section');
            if (heading) s.appendChild(el('h4', null, heading));
            s.appendChild(content);
            return s;
        }

        function tme(id) {
            return id ? 'https://t.me/' + String(id).replace(/^@/, '') : null;
        }

        function render(user) {
            body.replaceChildren();

            // خلاصه در هدر مودال می‌نشیند: نام، شناسه و تاریخ عضویت چیزی
            // نیست که بخواهد داخل بدنه هم دوباره تکرار شود.
            title.textContent = user.name || 'کاربر ' + user.chat_id;
            sub.textContent = 'عضو از ' + (user.created_at || 'تاریخ نامشخص');

            avatar.textContent = '';
            var initial = (String(user.name || '').trim() || String(user.chat_id || '؟')).charAt(0);
            avatar.appendChild(document.createTextNode(initial));
            if (user.avatar) {
                var img = document.createElement('img');
                img.src = user.avatar;
                img.alt = '';
                img.loading = 'lazy';
                img.addEventListener('error', function () { img.remove(); });
                avatar.appendChild(img);

                // The header photo zooms like every other photo in the panel.
                // The class and the attributes are set here because this avatar
                // is built in the browser, not by Blade.
                avatar.classList.add('avatar-btn');
                avatar.dataset.photo = user.avatar;
                avatar.dataset.photoName = user.name || ('کاربر ' + user.chat_id);
                avatar.setAttribute('role', 'button');
                avatar.setAttribute('tabindex', '0');
                avatar.setAttribute('aria-label', 'بزرگ‌نمایی عکس ' + (user.name || user.chat_id));
            } else {
                avatar.removeAttribute('data-photo');
                avatar.removeAttribute('role');
                avatar.removeAttribute('tabindex');
            }

            profile.href = user.profile_url || (INDEX + '?search=' + encodeURIComponent(user.chat_id));

            var chips = el('div', 'modal-chips');
            chips.appendChild(el('span', 'badge' + (user.used_test ? ' wait' : ''), user.used_test ? 'اکانت تست مصرف کرده' : 'بدون اکانت تست'));
            if (user.wallet === null) chips.appendChild(el('span', 'badge', 'بدون کیف پول'));
            if (user.telegram) {
                // نام کاربری، لینکِ t.me است: همان چیزی که در ستونِ «تلگرام»
                // و در صفحه‌ی جزئیات هم لینک می‌شود. رشته با textContent
                // ساخته می‌شود، پس کاراکترهای خطرناک از نامِ کاربری
                // نمی‌توانند مارک‌اپ شوند.
                var tg = el('a', 'badge', 'تلگرام: ' + user.telegram);
                tg.href = tme(user.telegram);
                tg.target = '_blank';
                tg.rel = 'noopener';
                chips.appendChild(tg);
            }
            body.appendChild(chips);

            var grid = el('div', 'kv');
            grid.appendChild(cell('نام', user.name));
            grid.appendChild(cell('تلگرام', user.telegram, { mono: true, link: tme(user.telegram), copy: true }));
            grid.appendChild(cell('شناسه گفتگو', user.chat_id, { mono: true, copy: true }));
            grid.appendChild(cell('ایمیل', user.email, { mono: true, copy: true }));
            grid.appendChild(cell('شماره تماس', user.phone, { mono: true, copy: true }));
            grid.appendChild(cell('موجودی کیف پول', user.wallet === null ? null : user.wallet_text + ' تومان'));
            body.appendChild(section('اطلاعات کاربر', grid));

            // The ledger itself lives in its own modal: a summary sheet that
            // grows a whole paginated table inside it stops being a summary.
            if (user.wallet !== null) {
                var walletLine = el('div', 'link-line');
                walletLine.appendChild(el('span', 'muted', 'تاریخچه‌ی تراکنش‌های کیف پول این کاربر'));

                var historyBtn = el('button', 'mini-btn', 'نمایش');
                historyBtn.type = 'button';
                historyBtn.dataset.walletHistory = user.chat_id;
                historyBtn.dataset.walletLabel = user.name || ('کاربر ' + user.chat_id);
                walletLine.appendChild(historyBtn);

                body.appendChild(walletLine);
            }

            var accounts = user.accounts || [];
            if (accounts.length === 0) {
                body.appendChild(section('اکانت‌ها', emptyState('این کاربر هیچ اکانتی ندارد.')));
            } else {
                var list = el('div', 'chip-list');
                accounts.forEach(function (account) {
                    var item = el('span', 'chip' + (account.status ? ' ' + account.status.tone : ''), account.label);
                    list.appendChild(item);
                });
                body.appendChild(section('اکانت‌ها (' + accounts.length + ')', list));
            }

            var orders = user.orders || [];
            if (orders.length === 0) {
                body.appendChild(section('آخرین سفارش‌ها', emptyState('این کاربر سفارشی ثبت نکرده است.')));
            } else {
                var table = el('table', 'mini-table');
                var head = el('thead');
                var hr = el('tr');
                ['شماره سفارش', 'مبلغ', 'وضعیت'].forEach(function (label) {
                    hr.appendChild(el('th', null, label));
                });
                head.appendChild(hr);
                table.appendChild(head);
                var tbody = el('tbody');
                orders.forEach(function (order) {
                    var tr = el('tr');
                    tr.appendChild(el('td', 'ltr', order.order_number));
                    tr.appendChild(el('td', null, order.price_text + ' تومان'));
                    tr.appendChild(el('td', null, order.status_label));
                    tbody.appendChild(tr);
                });
                table.appendChild(tbody);
                body.appendChild(section('آخرین سفارش‌ها', table));
            }
        }

        function emptyState(text) {
            var box = el('div', 'state');
            box.appendChild(el('span', 'glyph', '◌'));
            box.appendChild(el('div', null, text));
            return box;
        }

        function showError(message) {
            body.replaceChildren();

            var box = el('div', 'state bad');
            box.appendChild(el('span', 'glyph', '⚠'));
            box.appendChild(el('div', null, message || 'دریافت اطلاعات ممکن نشد.'));

            var retry = el('button', 'btn ghost', 'تلاش دوباره');
            retry.type = 'button';
            retry.addEventListener('click', function () { if (openId) load(openId); });
            box.appendChild(retry);

            body.appendChild(box);
            sub.textContent = 'اطلاعات دریافت نشد';
        }

        function load(id) {
            var mine = ++token;
            body.innerHTML = SKELETON;

            fetch(ENDPOINT.replace(':id', encodeURIComponent(id)), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json()
                    .catch(function () { return { error: 'پاسخ نامعتبر از سرور.' }; })
                    .then(function (data) { return { ok: response.ok, data: data }; });
            }).then(function (res) {
                if (mine !== token) return;
                if (!res.ok || res.data.error) { showError(res.data.error); return; }
                render(res.data);
            }).catch(function () {
                if (mine === token) showError('ارتباط با سرور برقرار نشد.');
            });
        }

        function open(id, label) {
            openId = id;
            lastFocus = document.activeElement;

            modal.hidden = false;
            window.cxLayerOpen(modal);

            title.textContent = label || 'جزئیات کاربر';
            sub.textContent = 'در حال دریافت اطلاعات…';

            load(id);

            var closeBtn = modal.querySelector('.modal-head [data-user-modal-close]');
            if (closeBtn) closeBtn.focus();
        }

        function close() {
            token++;
            openId = null;
            modal.hidden = true;
            window.cxLayerClose(modal);
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-user-details]');
            if (trigger) {
                // سطرِ جدول‌ها هم خودشان data-user-details دارند؛ کنترلِ
                // داخلشان (آواتارِ بزرگ‌نمایی، دکمه‌ی «جزئیات») باید کار
                // خودش را بکند — وگرنه preventDefault اینجا لینکِ صفحه‌ی
                // کامل را هم می‌خورد و رفتن به آن صفحه ممکن نمی‌شد.
                var control = event.target.closest('a[href], button:not([data-user-details]), input, select, textarea, label');
                if (control && control !== trigger) return;

                event.preventDefault();
                open(trigger.dataset.userDetails, trigger.dataset.userLabel || '');
                return;
            }

            if (event.target.closest('[data-user-modal-close]')) close();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden && window.cxIsTopLayer(modal)) close();
        });
    })();
</script>
