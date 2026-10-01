{{--
    تاریخچه‌ی تراکنش‌های کیف پول یک کاربر.

    هر دکمه‌ای با `data-wallet-history="<chat id>"` این مودال را باز می‌کند.
    داده از صفحه‌ی اول شروع می‌شود و با دکمه‌ی «بعدی» صفحه‌به‌صفحه می‌آید، پس
    کاربری با هزار تراکنش هم همان یک درخواست را می‌بیند که با ده تراکنش.

    مقدارها با textContent ساخته می‌شوند؛ تنها مقداری که به HTML می‌رود کلاس
    `money-up`/`money-down` است که خودِ سرور از روی نوع عملیات تعیین کرده.
--}}
<div class="modal" id="wallet-history-modal" hidden role="dialog" aria-modal="true" aria-labelledby="wallet-history-title">
    <div class="modal-layer" data-wallet-modal-close></div>

    <div class="modal-card">
        <header class="modal-head">
            <div class="identity">
                <span class="confirm-glyph" id="wallet-history-glyph" aria-hidden="true">₮</span>
                <div>
                    <h3 id="wallet-history-title">تاریخچه کیف پول</h3>
                    <p class="sub" id="wallet-history-sub">در حال دریافت اطلاعات…</p>
                </div>
            </div>

            <div class="tools">
                <button type="button" class="icon-btn" data-wallet-modal-close aria-label="بستن">✕</button>
            </div>
        </header>

        <div class="modal-body" id="wallet-history-body"></div>

        <footer class="modal-foot">
            <span class="muted grow" id="wallet-history-foot"></span>
            <button type="button" class="btn ghost" id="wallet-history-prev">قبلی</button>
            <button type="button" class="btn ghost" id="wallet-history-next">بعدی</button>
            <button type="button" class="btn" data-wallet-modal-close>بستن</button>
        </footer>
    </div>
</div>

<script>
    (function () {
        var ENDPOINT = @json(route('admin.users.wallet-history', ':id'));
        var modal = document.getElementById('wallet-history-modal');
        if (!modal) return;

        var body = document.getElementById('wallet-history-body');
        var title = document.getElementById('wallet-history-title');
        var sub = document.getElementById('wallet-history-sub');
        var foot = document.getElementById('wallet-history-foot');
        var prev = document.getElementById('wallet-history-prev');
        var next = document.getElementById('wallet-history-next');

        var chatId = null;
        var page = 1;
        var pages = 1;
        var token = 0;
        var lastFocus = null;

        var SKELETON =
            '<div class="skeleton" aria-hidden="true">' +
                '<div class="line w-40"></div>' +
                '<div class="blocks">' +
                    '<div class="line"></div><div class="line"></div>' +
                    '<div class="line"></div><div class="line"></div>' +
                    '<div class="line"></div><div class="line"></div>' +
                '</div>' +
            '</div>';

        function el(tag, className, text) {
            var node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined && text !== null) node.textContent = String(text);
            return node;
        }

        function render(payload) {
            body.replaceChildren();

            var chips = el('div', 'modal-chips');
            if (payload.balance_text !== null && payload.balance_text !== undefined) {
                chips.appendChild(el('span', 'badge ok', 'موجودی فعلی: ' + payload.balance_text + ' تومان'));
            } else {
                chips.appendChild(el('span', 'badge', 'بدون کیف پول'));
            }
            chips.appendChild(el('span', 'badge', payload.total + ' تراکنش'));
            body.appendChild(chips);

            var rows = payload.transactions || [];

            if (rows.length === 0) {
                var empty = el('div', 'state');
                empty.appendChild(el('span', 'glyph', '◌'));
                empty.appendChild(el('div', null, 'این کاربر هیچ تراکنشی ندارد.'));
                body.appendChild(empty);
                return;
            }

            var table = el('table', 'mini-table');
            var thead = el('thead');
            var hr = el('tr');
            ['مبلغ', 'عملیات', 'نوع', 'وضعیت', 'تاریخ'].forEach(function (head) {
                hr.appendChild(el('th', null, head));
            });
            thead.appendChild(hr);
            table.appendChild(thead);

            var tbody = el('tbody');
            rows.forEach(function (tx) {
                var tr = el('tr');

                // The direction is a server-chosen class on the amount, so the
                // same ledger reads identically here and in the page tables.
                var amount = el('td');
                var value = el('span', tx.direction === 'up' ? 'money-up' : 'money-down', tx.amount_text);
                amount.appendChild(value);
                amount.appendChild(el('span', 'muted', ' تومان'));
                tr.appendChild(amount);

                tr.appendChild(el('td', null, tx.operation));
                tr.appendChild(el('td', null, tx.type));
                tr.appendChild(el('td', null, el('span', 'badge ' + tx.status_tone, tx.status)));
                tr.appendChild(el('td', null, tx.created_at));

                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            body.appendChild(table);

            pages = payload.pages || 1;
            page = payload.page || 1;

            foot.textContent = 'صفحه ' + page + ' از ' + pages;
            prev.disabled = page <= 1;
            next.disabled = page >= pages;
        }

        function showError(message) {
            body.replaceChildren();

            var box = el('div', 'state bad');
            box.appendChild(el('span', 'glyph', '⚠'));
            box.appendChild(el('div', null, message || 'دریافت اطلاعات ممکن نشد.'));

            var retry = el('button', 'btn ghost', 'تلاش دوباره');
            retry.type = 'button';
            retry.addEventListener('click', function () { load(page); });
            box.appendChild(retry);

            body.appendChild(box);
            foot.textContent = '';
            prev.disabled = next.disabled = true;
        }

        function load(target) {
            if (!chatId) return;

            var mine = ++token;
            body.innerHTML = SKELETON;
            foot.textContent = '';

            var url = ENDPOINT.replace(':id', encodeURIComponent(chatId)) + '?page=' + encodeURIComponent(target);

            fetch(url, {
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
            chatId = id;
            page = 1;
            pages = 1;
            lastFocus = document.activeElement;

            modal.hidden = false;
            window.cxLayerOpen(modal);

            title.textContent = 'تاریخچه کیف پول';
            title.dataset.userName = label || '';
            sub.textContent = label ? label + ' · در حال دریافت اطلاعات…' : 'در حال دریافت اطلاعات…';

            prev.disabled = next.disabled = true;

            load(1);

            var closeBtn = modal.querySelector('.modal-head [data-wallet-modal-close]');
            if (closeBtn) closeBtn.focus();
        }

        function close() {
            token++;
            chatId = null;
            modal.hidden = true;
            window.cxLayerClose(modal);
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }

        prev.addEventListener('click', function () {
            if (page > 1) load(page - 1);
        });

        next.addEventListener('click', function () {
            if (page < pages) load(page + 1);
        });

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-wallet-history]');
            if (trigger) {
                event.preventDefault();
                open(trigger.dataset.walletHistory, trigger.dataset.walletLabel || '');
                return;
            }

            if (event.target.closest('[data-wallet-modal-close]')) close();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden && window.cxIsTopLayer(modal)) close();
        });
    })();
</script>
