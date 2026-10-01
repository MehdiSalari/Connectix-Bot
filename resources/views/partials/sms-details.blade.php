{{--
    Deposit sheet for one bank-SMS row (the legacy transactions modal).

    Three cards side by side - the deposit, its buyer, the raw SMS - with the
    purchase card spanning the full width below them when the deposit was
    matched to an order. The sheet is data-driven from the sms-payments
    details endpoint; the purchase card comes from the order sheet's own
    endpoint so plan/account assembly exists in exactly one place.
--}}
<div class="modal" id="sms-details-modal" hidden role="dialog" aria-modal="true" aria-labelledby="sms-details-title">
    <div class="modal-layer" data-sms-modal-close></div>

    <div class="modal-card">
        <header class="modal-head">
            <div class="identity">
                <span class="avatar" id="sms-details-glyph" aria-hidden="true">…</span>
                <div>
                    <h3 id="sms-details-title">در حال دریافت اطلاعات…</h3>
                    <p class="sub" id="sms-details-sub"></p>
                </div>
            </div>
            <div class="tools">
                <button type="button" class="icon-btn" data-sms-modal-close aria-label="بستن">✕</button>
            </div>
        </header>

        <div class="modal-body" id="sms-details-body">
            <div class="modal-section">
                <span class="skeleton" style="width: 132px; height: 17px;"></span>
                <span class="skeleton" style="width: min(220px, 76%); height: 14px;"></span>
                <span class="skeleton" style="width: min(184px, 68%); height: 14px;"></span>
            </div>
            <div class="modal-section"><span class="skeleton" style="width: 132px; height: 17px;"></span><span class="skeleton" style="width: min(220px, 76%); height: 14px;"></span></div>
            <div class="modal-section"><span class="skeleton" style="width: 132px; height: 17px;"></span><span class="skeleton" style="width: min(220px, 76%); height: 14px;"></span></div>
            <div class="modal-section"><span class="skeleton" style="width: 160px; height: 17px;"></span><span class="skeleton" style="width: min(240px, 76%); height: 14px;"></span><span class="skeleton" style="width: min(240px, 76%); height: 14px;"></span></div>
        </div>

        <footer class="modal-foot">
            <span class="muted grow" id="sms-details-foot"></span>
            <button type="button" class="btn ghost" data-sms-modal-close>بستن</button>
        </footer>
    </div>
</div>

<script>
(function () {
    var ENDPOINT = @json(route('admin.sms-payments.details', ':id'));
    var modal = document.getElementById('sms-details-modal');
    if (!modal) return;

    var body = document.getElementById('sms-details-body');
    var title = document.getElementById('sms-details-title');
    var sub = document.getElementById('sms-details-sub');
    var glyph = document.getElementById('sms-details-glyph');
    var foot = document.getElementById('sms-details-foot');

    var openId = null;
    var token = 0;
    var lastFocus = null;

    var SKELETON = body.innerHTML;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function copyButton(value) {
        var button = el('button', 'mini-btn', 'کپی');
        button.type = 'button';
        button.addEventListener('click', function () {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(function () { flip('کپی شد'); }, fallback);
                return;
            }
            fallback();

            function fallback() {
                var ok = false;
                try {
                    var scratch = document.createElement('textarea');
                    scratch.value = value;
                    scratch.setAttribute('readonly', 'readonly');
                    scratch.style.position = 'fixed';
                    scratch.style.opacity = '0';
                    document.body.appendChild(scratch);
                    scratch.select();
                    ok = document.execCommand('copy');
                    scratch.remove();
                } catch (error) {
                    ok = false;
                }
                flip(ok ? 'کپی شد' : 'کپی ممکن نشد');
            }

            function flip(label) {
                button.textContent = label;
                window.setTimeout(function () { button.textContent = 'کپی'; }, 1400);
            }
        });
        return button;
    }

    function revealButton(value) {
        var button = el('button', 'mini-btn', 'نمایش');
        button.type = 'button';
        var shown = false;
        button.addEventListener('click', function () {
            var line = button.closest('.sms-row');
            if (!line) return;
            shown = !shown;
            line.querySelector('.v').textContent = shown ? value : '••••••••';
            button.textContent = shown ? 'پنهان' : 'نمایش';
        });
        return button;
    }

    function emptyState(text) {
        var box = el('div', 'state');
        box.appendChild(el('span', 'glyph', '◌'));
        box.appendChild(el('div', null, text));
        return box;
    }

    function badState(text) {
        var box = el('div', 'state bad');
        box.appendChild(el('span', 'glyph', '⚠'));
        box.appendChild(el('div', null, text));
        return box;
    }

    /** One of the four cards: a modal-section heading over rows of content. */
    function card(label, wide) {
        var box = el('section', 'modal-section sms-card' + (wide ? ' wide' : ''));
        box.appendChild(el('h4', null, label));
        return box;
    }

    /**
     * A label/value line. value === null paints an em dash; the options pick
     * the value's shape (badge, money, code-ish mono, link, secret).
     */
    function row(box, label, value, options) {
        options = options || {};
        var empty = value === null || value === undefined || value === '';

        var line = el('div', 'sms-row');
        var key = el('span', 'k');
        key.appendChild(document.createTextNode(label));

        var v = el('span', 'v' + (options.mono ? ' ltr' : ''));
        if (empty) {
            v.classList.add('empty');
            v.textContent = '—';
        } else if (options.badge) {
            v.appendChild(el('span', 'badge ' + options.badge, String(value)));
        } else if (options.money) {
            v.appendChild(el('span', 'money-up', String(value)));
        } else if (options.secret) {
            v.textContent = '••••••••';
        } else if (options.link) {
            var a = el('a', null, String(value));
            a.href = options.link;
            a.target = '_blank';
            a.rel = 'noopener';
            v.appendChild(a);
        } else {
            v.textContent = String(value);
        }

        if (!empty && options.secret) key.appendChild(revealButton(String(value)));
        if (!empty && options.copy) key.appendChild(copyButton(String(value)));

        line.appendChild(key);
        line.appendChild(v);
        box.appendChild(line);
        return line;
    }

    /** A label line whose value is a node (a button) rather than text. */
    function actionRow(box, label, node) {
        var line = el('div', 'sms-row');
        line.appendChild(el('span', 'k', label));
        var v = el('span', 'v');
        v.appendChild(node);
        line.appendChild(v);
        box.appendChild(line);
        return line;
    }

    function telegramUrl(username) {
        return 'https://t.me/' + String(username).replace(/^@/, '');
    }

    function depositCard(deposit) {
        var box = card('اطلاعات واریز');
        row(box, 'مبلغ', deposit.amount_text ? deposit.amount_text + ' تومان' : null, { money: true });
        row(box, 'وضعیت', deposit.status_label, { badge: deposit.status_tone });
        row(box, 'تاریخ', deposit.created_at);
        row(box, 'نوع پرداخت', deposit.type_label);
        row(box, 'بانک', deposit.bank);
        return box;
    }

    function userCard(user) {
        var box = card('اطلاعات کاربر');
        if (!user) {
            box.appendChild(emptyState('برای این واریز کاربری پیدا نشد.'));
            return box;
        }

        row(box, 'نام', user.name);
        row(box, 'چت آیدی', user.chat_id, { mono: true });
        if (user.telegram) row(box, 'یوزرنیم', user.telegram, { link: telegramUrl(user.telegram) });

        if (user.profile_url) {
            var link = el('a', 'btn ghost sm', 'مشاهده کاربر');
            link.href = user.profile_url;
            actionRow(box, 'پروفایل', link);
        }
        return box;
    }

    function messageCard(message) {
        var box = card('متن پیام واریز');
        var text = el('div', 'sms-message');
        text.textContent = message || 'پیامی ثبت نشده';
        box.appendChild(text);
        return box;
    }

    function purchaseCard(order) {
        var box = card('جزئیات خرید اشتراک', true);
        var pair = el('div', 'sms-pair');

        var planBox = el('div', 'sms-col');
        planBox.appendChild(el('h5', null, 'پلن خریداری شده'));
        if (!order.plan) {
            planBox.appendChild(emptyState('پلنی برای این سفارش پیدا نشد.'));
        } else if (order.plan.unavailable) {
            planBox.appendChild(emptyState('این پلن دیگر در پنل موجود نیست (شناسه ' + order.plan.id + ').'));
        } else {
            row(planBox, 'عنوان', order.plan.title, { mono: true });
            row(planBox, 'حجم', order.plan.traffic);
            row(planBox, 'مدت', order.plan.period);
            row(planBox, 'دستگاه', order.plan.devices);
            row(planBox, 'قیمت', order.order && order.order.price_text ? order.order.price_text + ' تومان' : null, { money: true });
        }

        var accountBox = el('div', 'sms-col');
        accountBox.appendChild(el('h5', null, 'اکانت فعال شده'));
        var account = order.account;
        if (!account) {
            accountBox.appendChild(emptyState('این سفارش هنوز اکانتی برای کاربر نساخته است.'));
        } else if (account.error) {
            accountBox.appendChild(badState(account.error));
        } else {
            row(accountBox, 'نام', account.name);
            row(accountBox, 'یوزرنیم', account.username, { mono: true, copy: true });
            row(accountBox, 'رمز عبور', account.password, { mono: true, secret: true, copy: true });
            row(accountBox, 'انقضا', account.expire_date);
            row(accountBox, 'ترافیک مصرفی', trafficPair(account), { mono: true });
        }

        pair.appendChild(planBox);
        pair.appendChild(accountBox);
        box.appendChild(pair);
        return box;
    }

    function purchaseErrorCard() {
        var box = card('جزئیات خرید اشتراک', true);
        box.appendChild(badState('جزئیات خرید برای این سفارش در دسترس نیست.'));
        return box;
    }

    /** "used / total" as the legacy sheet showed it, or whichever exists. */
    function trafficPair(account) {
        if (account.used_traffic && account.total_traffic) {
            return account.used_traffic + ' / ' + account.total_traffic;
        }
        return account.used_traffic || account.total_traffic || null;
    }

    function render(data, order, orderFailed) {
        var grid = el('div', 'sms-grid');
        grid.appendChild(depositCard(data.deposit || {}));
        grid.appendChild(userCard(data.user));
        grid.appendChild(messageCard(data.message));
        if (data.order_endpoint) {
            grid.appendChild(orderFailed ? purchaseErrorCard() : purchaseCard(order || {}));
        }
        body.replaceChildren(grid);
    }

    function paintHeader(data, label) {
        var deposit = data.deposit || {};
        glyph.textContent = '💵';
        title.textContent = deposit.type_label || 'جزئیات تراکنش واریز';
        sub.textContent = (label || 'واریز ' + (deposit.amount_text || '') + ' تومان') +
            (deposit.created_at ? ' · ' + deposit.created_at : '');
        foot.textContent = deposit.bank ? 'بانک: ' + deposit.bank : '';
    }

    function showError(text) {
        glyph.textContent = '⚠';
        title.textContent = 'دریافت جزئیات ممکن نشد';
        sub.textContent = '';
        foot.textContent = '';
        body.innerHTML = '';
        body.appendChild(badState(text));
    }

    function load(id, label) {
        var mine = ++token;
        body.innerHTML = SKELETON;

        fetch(ENDPOINT.replace(':id', String(id)), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (json) {
                    if (!response.ok || json.error) throw new Error(json.error || String(response.status));
                    return json;
                });
            })
            .then(function (data) {
                if (mine !== token) return null;
                paintHeader(data, label);

                if (!data.order_endpoint) {
                    render(data, null, false);
                    return null;
                }

                return fetch(data.order_endpoint, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                }).then(function (response) {
                    if (!response.ok) throw new Error(String(response.status));
                    return response.json();
                }).then(function (order) {
                    if (mine === token) render(data, order, false);
                }).catch(function () {
                    // The sheet is still worth showing: the three cards above
                    // are complete without the purchase one.
                    if (mine === token) render(data, null, true);
                });
            })
            .catch(function (error) {
                if (mine === token) showError(error && error.message ? error.message : 'خطای ناشناخته');
            });
    }

    function openSheet(trigger, id, label) {
        if (!modal.hidden) return;
        lastFocus = document.activeElement;
        openId = String(id);
        title.textContent = 'در حال دریافت اطلاعات…';
        sub.textContent = label || '';
        glyph.textContent = '…';
        foot.textContent = '';
        modal.hidden = false;
        // No callback here: cxLayerOpen only tracks the stack. Hiding the
        // sheet is closeSheet()'s job - passing a callback that never runs
        // is exactly what made the close buttons dead.
        cxLayerOpen(modal);
        load(id, label);

        var closeBtn = modal.querySelector('.modal-head [data-sms-modal-close]');
        if (closeBtn) closeBtn.focus();
    }

    function closeSheet() {
        if (modal.hidden) return;
        token++;               // a fetch still in flight must not repaint this
        openId = null;
        modal.hidden = true;
        body.innerHTML = SKELETON;
        window.cxLayerClose(modal);
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.addEventListener('click', function (event) {
        var closer = event.target.closest && event.target.closest('[data-sms-modal-close]');
        if (closer) {
            if (cxIsTopLayer(modal)) closeSheet();
            return;
        }

        // The row and its «جزئیات» button carry the same id, so closest()
        // picks the innermost one and a single open happens either way.
        var trigger = event.target.closest && event.target.closest('[data-sms-details]');
        if (trigger && !event.target.closest('[data-sms-modal-close]')) {
            event.preventDefault();
            // The label lives on the row; the inner button may be the target.
            var host = trigger.closest('tr');
            var label = trigger.getAttribute('data-sms-label') || (host && host.getAttribute('data-sms-label'));
            openSheet(trigger, trigger.getAttribute('data-sms-details'), label);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !cxIsTopLayer(modal)) return;
        if (modal.hidden) return;
        if (!document.querySelector('.mini-btn:focus')) event.preventDefault();
        closeSheet();
    });
})();
</script>
