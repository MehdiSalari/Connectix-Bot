{{--
    جزئیات سفارش به شکل مودال.

    هر دکمه‌ای با `data-order-details="<payment id>"` این مودال را باز می‌کند
    (لیست سفارش‌ها همین کار را می‌کند). پاسخ از پنل Connectix و کاتالوگ پلن‌ها
    می‌آید، پس همه‌ی مقادیر با textContent ساخته می‌شوند نه innerHTML تا داده‌ی
    ریموت در DOM تزریق نشود.
--}}
<div class="modal" id="order-details-modal" hidden role="dialog" aria-modal="true" aria-labelledby="order-details-title">
    <div class="modal-layer" data-order-modal-close></div>

    <div class="modal-card">
        <header class="modal-head">
            <div class="identity">
                {{-- آواتار خریدار. یک <span> با نقش دکمه است تا با data-photo
                     لایت‌باکس را باز کند؛ تا پیش از آمدن پاسخ حرف اول نام را
                     نشان می‌دهد و بعد جای خود را به عکس می‌دهد. --}}
                <span class="avatar" id="order-details-avatar" role="button" tabindex="0"
                      aria-label="بزرگ‌نمایی عکس خریدار"></span>
                <div>
                    <h3 id="order-details-title">جزئیات تراکنش</h3>
                    <p class="sub" id="order-details-sub"></p>
                </div>
            </div>

            <div class="tools">
                <button type="button" class="icon-btn" data-order-modal-close aria-label="بستن">✕</button>
            </div>
        </header>

        <div class="modal-body" id="order-details-body"></div>

        <footer class="modal-foot">
            <span class="muted grow" id="order-details-foot"></span>
            <button type="button" class="btn ghost" data-order-modal-close>بستن</button>
        </footer>
    </div>
</div>

<script>
    (function () {
        var ENDPOINT = @json(route('admin.orders.details', ':id'));
        var modal = document.getElementById('order-details-modal');
        if (!modal) return;

        var body = document.getElementById('order-details-body');
        var title = document.getElementById('order-details-title');
        var sub = document.getElementById('order-details-sub');
        var avatar = document.getElementById('order-details-avatar');
        var foot = document.getElementById('order-details-foot');

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
                var id = 'order-secret-' + Math.random().toString(36).slice(2, 9);
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

        function chips(order) {
            var row = el('div', 'modal-chips');
            var tone = order.status === '1' ? 'ok' : (order.status === '0' ? 'no' : 'wait');

            row.appendChild(el('span', 'badge ' + tone, order.status_label || '—'));
            if (order.method) row.appendChild(el('span', 'badge', order.method));
            if (order.coupon) row.appendChild(el('span', 'badge', 'کوپن: ' + order.coupon));
            if (order.created_at) row.appendChild(el('span', 'badge', order.created_at));

            return row;
        }

        function orderSection(order) {
            var grid = el('div', 'kv');
            grid.appendChild(cell('شماره سفارش', order.order_number, { mono: true, copy: true }));
            grid.appendChild(cell('مبلغ فروش', order.price_text + ' تومان'));
            return section('اطلاعات تراکنش', grid);
        }

        function buyerSection(buyer) {
            if (!buyer || (!buyer.name && !buyer.telegram && !buyer.chat_id)) {
                return null;
            }

            var t = function (id) { return id ? 'https://t.me/' + String(id).replace(/^@/, '') : null; };

            var grid = el('div', 'kv');
            grid.appendChild(cell('شناسه گفتگو', buyer.chat_id, { mono: true, copy: true }));
            grid.appendChild(cell('تلگرام', buyer.telegram, { mono: true, link: t(buyer.telegram) }));

            // Opens the panel's own user sheet, not the t.me page: the admin
            // works in this panel, and the sheet carries the wallet, the
            // accounts and the payments that t.me knows nothing about. It is a
            // button rather than a link because it is handled in the page.
            var row = el('div', 'cell');
            row.appendChild(el('span', 'k', 'مشاهده کاربر'));
            var btn = el('button', 'mini-btn', buyer.name || buyer.telegram || 'کاربر');
            btn.type = 'button';
            btn.setAttribute('data-user-details', buyer.chat_id || '');
            btn.setAttribute('data-user-label', buyer.name || ('کاربر ' + (buyer.chat_id || '')));
            row.appendChild(btn);
            grid.appendChild(row);

            return section('کاربر', grid);
        }

        function planSection(plan) {
            if (!plan) return null;

            if (plan.unavailable) {
                return section('اطلاعات پلن', emptyState('این پلن دیگر در کاتالوگ پنل موجود نیست. شناسه: ' + plan.id));
            }

            var grid = el('div', 'kv');
            grid.appendChild(cell('عنوان', plan.title, { mono: true }));
            grid.appendChild(cell('حجم', plan.traffic));
            grid.appendChild(cell('مدت زمان', plan.period));
            grid.appendChild(cell('تعداد دستگاه', plan.devices));
            grid.appendChild(cell('نوع', plan.type));

            (plan.extras || []).forEach(function (extra) {
                grid.appendChild(cell('ویژگی', extra));
            });

            return section('اطلاعات پلن', grid);
        }

        function accountSection(account) {
            if (!account) {
                return section('اطلاعات اکانت', emptyState('این سفارش هنوز اکانتی در پنل نساخته است. با تایید سفارش ساخته می‌شود.'));
            }

            if (account.error) {
                var box = el('div', 'state bad');
                box.appendChild(el('span', 'glyph', '⚠'));
                box.appendChild(el('div', null, account.error));
                return section('اطلاعات اکانت', box);
            }

            var grid = el('div', 'kv');
            grid.appendChild(cell('نام', account.name));
            grid.appendChild(cell('یوزرنیم', account.username, { mono: true, copy: true }));
            grid.appendChild(cell('ایمیل', account.email));
            grid.appendChild(cell('رمز عبور', account.password, { mono: true, secret: true, copy: true }));
            grid.appendChild(cell('تاریخ انقضا', account.expire_date));
            grid.appendChild(cell('تعداد دستگاه مجاز', account.devices));
            grid.appendChild(cell('ترافیک مصرفی', trafficLabel(account)));

            var sectionNode = section('اطلاعات اکانت', grid);

            if (account.subscription_link) {
                var link = el('div', 'link-line');
                link.appendChild(el('code', null, account.subscription_link));

                var open = el('a', 'mini-btn', 'باز کردن');
                open.href = account.subscription_link;
                open.target = '_blank';
                open.rel = 'noopener';
                open.style.textDecoration = 'none';

                link.appendChild(open);
                link.appendChild(copyButton(account.subscription_link));

                sectionNode.appendChild(link);
            }

            return sectionNode;
        }

        function trafficLabel(account) {
            var used = account.used_traffic;
            var total = account.total_traffic;

            if (!used && !total) return null;
            if (used && total) return used + ' / ' + total;
            return used || total;
        }

        function render(payload) {
            body.replaceChildren();

            var buyer = payload.buyer || null;

            paintHeader(buyer);

            body.appendChild(chips(payload.order || {}));
            body.appendChild(orderSection(payload.order || {}));

            var buyerNode = buyerSection(buyer);
            if (buyerNode) body.appendChild(buyerNode);

            var plan = planSection(payload.plan);
            if (plan) body.appendChild(plan);

            body.appendChild(accountSection(payload.account));
        }

        // The header used to keep its placeholder forever: nothing wrote to
        // avatar/sub after the response arrived, so the modal stayed on
        // "در حال دریافت اطلاعات…" over a fully loaded body.
        function paintHeader(buyer) {
            var name = (buyer && buyer.name && String(buyer.name).trim())
                ? String(buyer.name).trim()
                : '';

            title.textContent = name || 'جزئیات تراکنش';
            sub.textContent = buyer && buyer.telegram ? '@' + buyer.telegram : '';

            avatar.replaceChildren();
            avatar.classList.remove('avatar-btn');
            avatar.removeAttribute('data-photo');
            avatar.removeAttribute('role');
            avatar.removeAttribute('tabindex');

            if (!name) avatar.textContent = 'C';
            else if (buyer.avatar) {
                var img = el('img');
                img.src = buyer.avatar;
                img.alt = '';
                img.loading = 'lazy';
                img.addEventListener('error', function () {
                    img.remove();
                    avatar.textContent = name.charAt(0).toUpperCase();
                });
                avatar.appendChild(img);

                avatar.classList.add('avatar-btn');
                avatar.dataset.photo = buyer.avatar;
                avatar.dataset.photoName = name;
                avatar.setAttribute('role', 'button');
                avatar.setAttribute('tabindex', '0');
                avatar.setAttribute('aria-label', 'بزرگ‌نمایی عکس ' + name);
            } else {
                avatar.textContent = name.charAt(0).toUpperCase();
            }
        }

        function showError(message) {
            body.replaceChildren();

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

            title.textContent = 'جزئیات تراکنش';
            avatar.textContent = '…';
            sub.textContent = 'در حال دریافت اطلاعات…';
            foot.textContent = '';

            load(id);

            var closeBtn = modal.querySelector('.modal-head [data-order-modal-close]');
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
            var trigger = event.target.closest('[data-order-details]');
            if (!trigger) return;
            event.preventDefault();
            open(trigger.dataset.orderDetails, trigger.dataset.orderLabel || '');
        });

        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-order-modal-close]')) close();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden && window.cxIsTopLayer(modal)) close();
        });
    })();
</script>
