{{--
    جزئیات اکانت (کلاینت Connectix) به شکل مودال.

    این پارشال از داخل layouts/admin روی همه‌ی صفحات پنل بارگذاری می‌شود، پس هر
    المنتی — یک سطر جدول، یک دکمه، یک کارت — می‌تواند با `data-client-details`
    همین مودال را باز کند؛ لازم نیست صفحه‌ی پروفایل باشد.

    پاسخ پنل از API می‌آید، پس همه‌ی مقادیر با textContent ساخته می‌شوند نه
    innerHTML تا داده‌ی ریموت در DOM تزریق نشود.
--}}
<div class="modal" id="client-details-modal" hidden role="dialog" aria-modal="true" aria-labelledby="client-details-title">
    <div class="modal-layer" data-modal-close></div>

    <div class="modal-card">
        <header class="modal-head">
            <div class="identity">
                <span class="avatar" id="client-details-avatar" aria-hidden="true">C</span>
                <div>
                    <h3 id="client-details-title">جزئیات اکانت</h3>
                    <p class="sub" id="client-details-sub">در حال دریافت اطلاعات از پنل Connectix…</p>
                </div>
            </div>

            <div class="tools">
                {{-- Deep link into the seller panel. Hidden until the payload
                     arrives, because the id is only known then (the row's own
                     data is not trusted here). target=_blank + rel=noopener:
                     the panel is a foreign origin and must not get a handle
                     on this window. --}}
                <a class="btn ghost" id="client-details-panel-link" hidden
                   target="_blank" rel="noopener noreferrer">مشاهده در پنل Connectix</a>
                <button type="button" class="icon-btn" data-modal-close aria-label="بستن">✕</button>
            </div>
        </header>

        <div class="modal-body" id="client-details-body"></div>

        <footer class="modal-foot">
            <span class="muted grow" id="client-details-source"></span>
            {{-- حذف، فقط برای ادمین: هم از دیتابیس محلی و هم از پنل Connectix
                 (کنترلر اول پنل را خبر می‌کند و اگر پنل نپذیرد چیزی پاک نمی‌شود).
                 تایید با دیالوگ پنل است، نه confirm() مرورگر؛ دکمه هم‌اندازه‌ی
                 بقیه‌ی دکمه‌ها (class btn، نه یک استایل جدا) تا کف مودال یکدست
                 بماند. آدرس با :id قالب است و اسکریپت هنگام باز شدن شناسه را می‌گذارد. --}}
            @if (auth('admin')->user()?->isAdmin())
                <form method="post" id="client-details-delete-form" class="inline-form" hidden
                      action="{{ route('admin.clients.destroy', ['client' => ':id']) }}"
                      data-action="{{ route('admin.clients.destroy', ['client' => ':id']) }}"
                      data-confirm="این اکانت از دیتابیس و از پنل Connectix حذف می‌شود. این کار برگشت‌پذیر نیست."
                      data-confirm-title="حذف اکانت"
                      data-confirm-accept="حذف کن">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn danger">حذف اکانت</button>
                </form>
            @endif
            <button type="button" class="btn ghost" data-modal-close>بستن</button>
        </footer>
    </div>
</div>

<script>
    (function () {
        var ENDPOINT = @json(route('admin.clients.show', ':id'));
        var modal = document.getElementById('client-details-modal');
        if (!modal) return;

        var body = document.getElementById('client-details-body');
        var title = document.getElementById('client-details-title');
        var sub = document.getElementById('client-details-sub');
        var avatar = document.getElementById('client-details-avatar');
        var source = document.getElementById('client-details-source');
        var panelLink = document.getElementById('client-details-panel-link');

        var openId = null;
        var token = 0;          // هر باز شدن یک توکن؛ جواب دیررسیده‌ی قبلی بی‌اثر می‌شود
        var lastFocus = null;

        var SKELETON =
            '<div class="skeleton" aria-hidden="true">' +
                '<div class="line w-40"></div>' +
                '<div class="blocks">' +
                    '<div class="line"></div><div class="line"></div>' +
                    '<div class="line"></div><div class="line"></div>' +
                '</div>' +
                '<div class="line w-70"></div>' +
                '<div class="line w-90"></div>' +
            '</div>';

        /* ------------------------------------------------------------------ */
        /* ساخت DOM                                                            */
        /* ------------------------------------------------------------------ */

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
                var text = typeof value === 'function' ? value() : String(value);

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

        function revealButton(getter) {
            var btn = el('button', 'mini-btn', 'نمایش');
            btn.type = 'button';
            btn.addEventListener('click', function () {
                var shown = btn.dataset.shown === '1';
                btn.dataset.shown = shown ? '0' : '1';
                btn.textContent = shown ? 'نمایش' : 'پنهان';
                var target = document.getElementById(btn.dataset.for);
                if (target) target.textContent = shown ? '••••••••' : getter();
            });
            return btn;
        }

        function cell(label, value, options) {
            options = options || {};

            var wrap = el('div', 'cell');
            var key = el('div', 'k');
            key.appendChild(el('span', null, label));

            if (options.copy && value) key.appendChild(copyButton(options.copy === true ? value : options.copy));
            if (options.secret && value) {
                var id = 'secret-' + Math.random().toString(36).slice(2, 9);
                var rv = revealButton(function () { return value; });
                rv.dataset.for = id;
                key.appendChild(rv);
                options.id = id;
            }

            var val = el('div', 'v' + (options.mono ? ' ltr' : ''));
            var empty = value === null || value === undefined || value === '';

            if (empty) {
                val.classList.add('empty');
                val.textContent = '—';
            } else if (options.secret) {
                val.id = options.id;
                val.textContent = '••••••••';
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
            s.appendChild(el('h4', null, heading));
            s.appendChild(content);
            return s;
        }

        function emptyState(text) {
            var box = el('div', 'state');
            box.appendChild(el('span', 'glyph', '◌'));
            box.appendChild(el('div', null, text));
            return box;
        }

        /* ------------------------------------------------------------------ */
        /* بخش‌های مودال                                                        */
        /* ------------------------------------------------------------------ */

        function chips(c) {
            var row = el('div', 'modal-chips');
            var plans = Array.isArray(c.plans) ? c.plans : [];
            var active = plans.filter(function (p) { return p && p.is_active && !p.is_in_queue; }).length;
            var queued = plans.filter(function (p) { return p && p.is_in_queue; }).length;

            row.appendChild(el('span', 'badge', plans.length + ' پلن'));
            if (active) row.appendChild(el('span', 'badge ok', active + ' فعال'));
            if (queued) row.appendChild(el('span', 'badge wait', queued + ' در صف'));
            if (c.count_of_devices !== null && c.count_of_devices !== undefined) {
                row.appendChild(el('span', 'badge', c.count_of_devices + ' دستگاه'));
            }
            if (c.is_child_protection_enabled) row.appendChild(el('span', 'badge ok', 'محافظت کودک'));
            if (c.expire_date) row.appendChild(el('span', 'badge wait', 'انقضا: ' + c.expire_date));

            return row;
        }

        function infoGrid(c) {
            var grid = el('div', 'kv');
            var t = function (id) { return id ? 'https://t.me/' + String(id).replace(/^@/, '') : null; };

            grid.appendChild(cell('شناسه اکانت', c.id, { mono: true, copy: true }));
            grid.appendChild(cell('نام کاربری', c.username, { mono: true, copy: true }));
            grid.appendChild(cell('نام', c.name));
            grid.appendChild(cell('ایمیل', c.email));
            grid.appendChild(cell('تلفن', c.phone, { mono: true }));
            grid.appendChild(cell('شناسه گفتگو', c.chat_id, { mono: true, copy: true }));
            grid.appendChild(cell('تلگرام', c.telegram_id, { mono: true, link: t(c.telegram_id) }));
            grid.appendChild(cell('تاریخ انقضا', c.expire_date));
            grid.appendChild(cell('تعداد دستگاه', c.count_of_devices));
            grid.appendChild(cell('افزوده توسط', c.added_by));
            grid.appendChild(cell('محافظت کودک', c.is_child_protection_enabled ? 'فعال' : 'غیرفعال'));
            grid.appendChild(cell('رمز عبور', c.password, { mono: true, secret: true }));
            grid.appendChild(cell('یادداشت', c.notes));

            return grid;
        }

        function subscription(link) {
            var box = el('div', 'link-line');
            box.appendChild(el('code', null, link));

            var open = el('a', 'mini-btn', 'باز کردن');
            open.href = link;
            open.target = '_blank';
            open.rel = 'noopener';
            open.style.textDecoration = 'none';

            box.appendChild(open);
            box.appendChild(copyButton(link));
            return section('لینک اشتراک', box);
        }

        function planStatus(p) {
            if (p.is_in_queue) return ['wait', 'در صف'];
            if (p.is_active && p.is_active !== 0) return ['ok', 'فعال'];
            return ['no', 'غیرفعال'];
        }

        function plans(list) {
            if (!list.length) return section('پلن‌ها', emptyState('پلنی برای این اکانت ثبت نشده است.'));

            var wrap = el('div', 'table-wrap');
            var table = el('table');
            var head = el('thead');
            var headRow = el('tr');

            ['پلن', 'ترافیک', 'قیمت', 'ساخت', 'انقضا', 'وضعیت'].forEach(function (h) {
                headRow.appendChild(el('th', null, h));
            });

            head.appendChild(headRow);
            table.appendChild(head);

            var foot = el('tbody');

            list.forEach(function (p) {
                var row = el('tr');
                row.appendChild(el('td', null, p.name || '—'));
                row.appendChild(el('td', 'ltr', p.total_used_traffic || '—'));
                row.appendChild(el('td', null, p.price || '—'));
                row.appendChild(el('td', null, p.activated_at || p.created_at || '—'));
                row.appendChild(el('td', null, p.expire_date || p.expired_at || '—'));

                var status = planStatus(p);
                var cellWrap = el('td');
                cellWrap.appendChild(el('span', 'badge ' + status[0], status[1]));
                row.appendChild(cellWrap);

                foot.appendChild(row);
            });

            table.appendChild(foot);
            wrap.appendChild(table);

            return section('پلن‌ها', wrap);
        }

        function localRecord(local) {
            var grid = el('div', 'kv');

            grid.appendChild(cell('نام کاربری محلی', local.username, { mono: true, copy: true }));
            grid.appendChild(cell('تعداد دستگاه', local.count_of_devices));
            grid.appendChild(cell('ساخته شده', local.created_at));
            if (local.password) {
                grid.appendChild(cell('رمز محلی', local.password, { mono: true, secret: true, copy: true }));
            }

            return section('رکورد محلی ربات', grid);
        }

        function render(payload) {
            var c = payload.client || {};
            var local = payload.local || null;

            var heading = (c.name && String(c.name).trim())
                ? String(c.name).trim()
                : (c.username ? c.username : 'جزئیات اکانت');

            title.textContent = heading;
            avatar.textContent = heading.trim().charAt(0).toUpperCase() || 'C';
            sub.textContent = c.username ? c.username : (c.id || '');
            source.textContent = c.added_by ? 'ارائه‌دهنده: ' + c.added_by : '';

            // The panel link is server-built, so it is set with setAttribute
            // rather than assigned to .href from the payload object.
            if (panelLink && payload.panel_url) {
                panelLink.setAttribute('href', String(payload.panel_url));
                panelLink.hidden = false;
            }

            body.replaceChildren();
            body.appendChild(chips(c));
            body.appendChild(section('اطلاعات اکانت', infoGrid(c)));

            if (c.subscription_link) body.appendChild(subscription(c.subscription_link));

            body.appendChild(plans(Array.isArray(c.plans) ? c.plans : []));
            if (local) body.appendChild(localRecord(local));
        }

        function showError(message) {
            body.replaceChildren();

            // A failed load leaves the previous account's panel link on screen,
            // which would point at an account this modal is not showing.
            if (panelLink) panelLink.hidden = true;

            var box = el('div', 'state bad');
            box.appendChild(el('span', 'glyph', '⚠'));
            box.appendChild(el('div', null, message || 'دریافت اطلاعات ممکن نشد.'));

            var retry = el('button', 'btn ghost', 'تلاش دوباره');
            retry.type = 'button';
            retry.addEventListener('click', function () {
                if (openId) load(openId);
            });
            box.appendChild(retry);

            body.appendChild(box);
            sub.textContent = 'اطلاعات دریافت نشد';
        }

        /* ------------------------------------------------------------------ */
        /* باز و بسته کردن                                                     */
        /* ------------------------------------------------------------------ */

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
                if (mine !== token) return;                 // مودال بسته یا عوض شده
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

            title.textContent = label || 'جزئیات اکانت';
            avatar.textContent = '…';
            sub.textContent = 'در حال دریافت اطلاعات از پنل Connectix…';
            source.textContent = '';

            // Reset from the previous account: otherwise a stale href stays
            // clickable during the load of the next one.
            if (panelLink) panelLink.hidden = true;

            var deleteForm = document.getElementById('client-details-delete-form');
            if (deleteForm) {
                deleteForm.action = deleteForm.dataset.action.replace(':id', encodeURIComponent(id));
                deleteForm.hidden = false;
            }

            load(id);

            var closeBtn = modal.querySelector('.modal-head [data-modal-close]');
            if (closeBtn) closeBtn.focus();
        }

        function close() {
            token++;                      // جواب‌های در راه را بی‌اثر می‌کند
            openId = null;
            modal.hidden = true;
            window.cxLayerClose(modal);

            var deleteForm = document.getElementById('client-details-delete-form');
            if (deleteForm) deleteForm.hidden = true;

            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }

        /* هر المنتی با data-client-details این مودال را باز می‌کند. */
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-client-details]');
            if (!trigger) return;

            // کنترل‌های داخل المنتِ میزبان (لینک، فرم، دکمه‌ی دیگر) کار خودشان را می‌کنند
            var control = event.target.closest('a[href], input, select, textarea, label, form, button:not([data-client-details])');
            if (control && control !== trigger) return;

            event.preventDefault();
            open(trigger.dataset.clientDetails, trigger.dataset.clientLabel || '');
        });

        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-modal-close]')) close();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden && window.cxIsTopLayer(modal)) close();
        });
    })();
</script>
