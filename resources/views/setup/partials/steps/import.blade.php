<div class="alert warn">
    هر دو بخش زیر فقط رکورد <strong>اضافه</strong> می‌کنند. هیچ رکوردی حذف یا بازنویسی نمی‌شود، پس اگر
    نصب نیمه‌کاره بود، اجرای دوباره‌ی همین دکمه‌ها امن است و از همان‌جا ادامه می‌دهد.
</div>

{{-- Live counters: the import runs as one long POST while this box polls
     GET setup.import.progress (legacy did the same via setup_progress.php). --}}
<div class="card" id="ip-box" hidden>
    <div id="ip-head">
        <span id="ip-phase" class="muted">—</span>
        <strong id="ip-percent">۰٪</strong>
    </div>
    <div class="bar"><div id="ip-bar" style="width: 0%"></div></div>
    <div id="ip-count" class="muted"></div>
    <pre class="log" id="ip-log" hidden></pre>
</div>

<h3>انتقال از دیتابیس نصب قبلی</h3>
<form method="POST" action="{{ route('setup.import') }}" class="stack">
    @csrf
    <input type="hidden" name="action" value="legacy">

    <div class="form-grid two">
        <label>
            <span>هاست دیتابیس</span>
            <input type="text" name="db_host" value="{{ old('db_host', $env['DB_HOST'] ?? '') }}">
        </label>
        <label>
            <span>پورت</span>
            <input type="text" name="db_port" value="{{ old('db_port', $env['DB_PORT'] ?? '3306') }}">
        </label>
        <label>
            <span>نام دیتابیس</span>
            <input type="text" name="db_database" value="{{ old('db_database', $env['DB_DATABASE'] ?? '') }}">
        </label>
        <label>
            <span>کاربر</span>
            <input type="text" name="db_username" value="{{ old('db_username', $env['DB_USERNAME'] ?? '') }}">
        </label>
        <label>
            <span>رمز عبور</span>
            <input type="password" name="db_password" value="{{ old('db_password', $env['DB_PASSWORD'] ?? '') }}">
        </label>
        <div class="field-note">
            <span>توضیحات</span>
            <p>اگر دیتابیس قبلی روی همین سرور است، همین مقادیر را نگه دارید.</p>
        </div>
    </div>

    <label class="checkbox">
        <input type="checkbox" name="confirm" value="1" checked>
        <span>واقعاً بنویس (بدون تیک فقط «حالت آزمایشی» و شمارش رکوردها اجرا می‌شود)</span>
    </label>

    <div class="actions">
        <button type="submit" class="btn primary">شروع انتقال</button>
        <a href="{{ route('setup.show', ['step' => 'database']) }}" class="btn ghost">مرحله قبل</a>
        <a href="{{ route('setup.show', ['step' => 'bot-config']) }}" class="btn">مرحله بعد →</a>
    </div>
</form>

<h3>خواندن مشتریان از پنل</h3>
<p class="muted">
    معادل گام آخر نسخه legacy: مشتریان و کیف پول‌ها از پنل فروشنده خوانده می‌شوند.
    <strong>همه صفحات پنل</strong> خوانده می‌شود (مثلاً ۳۴ صفحه برای ۶۶۶ مشتری) و درصد پیشرفت همین‌جا نمایش داده می‌شود؛
    تا پایان انتقال نباید آن را کنسل کنید.
</p>

<form method="POST" action="{{ route('setup.import') }}" class="stack">
    @csrf
    <input type="hidden" name="action" value="panel">
    <div class="actions">
        <button type="submit" class="btn primary">خواندن از پنل فروشنده</button>
        <a href="{{ route('setup.show', ['step' => 'bot-config']) }}" class="btn">مرحله بعد →</a>
    </div>
</form>

@php
    // Flash first — an inline run still sets it. An out-of-process run has no
    // request left by the time it finishes, so its report travels in the
    // progress file and is picked up here after the final navigation.
    $report = session('report');
    if (! is_array($report) || $report === []) {
        $report = \App\Services\Setup\ImportProgress::read()['report'] ?? null;
    }
@endphp
@if(is_array($report) && $report !== [])
    <h3>گزارش انتقال</h3>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>بخش</th>
                    <th>خوانده</th>
                    <th>نوشته</th>
                    <th>رد شده</th>
                    <th>خطا</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report as $name => $counts)
                    <tr>
                        <td><strong>{{ $name }}</strong></td>
                        @if(is_array($counts) && isset($counts['read']))
                            <td>{{ number_format((int) $counts['read']) }}</td>
                            <td>{{ number_format((int) ($counts['written'] ?? 0)) }}</td>
                            <td>{{ number_format((int) ($counts['skipped'] ?? 0)) }}</td>
                            <td>{{ number_format((int) ($counts['failed'] ?? 0)) }}</td>
                        @else
                            <td colspan="4">
                                @if(is_array($counts))
                                    @foreach($counts as $k => $v)
                                        {{ $k }}: {{ is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}@if(!$loop->last) · @endif
                                    @endforeach
                                @else
                                    {{ $counts }}
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<h3>وضعیت فعلی جدول‌ها</h3>
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>جدول</th>
                <th>تعداد رکورد</th>
            </tr>
        </thead>
        <tbody>
            @foreach(($counts ?? []) as $table => $count)
                <tr>
                    <td>{{ $table }}</td>
                    <td>{{ number_format((int) $count) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<style>
    #ip-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 8px; }
    #ip-head strong { color: var(--accent); font-variant-numeric: tabular-nums; }
    #ip-count { font-size: var(--fs-xs); }
    #ip-log { margin-top: 12px; }
    form.is-importing button,
    form.is-importing a { opacity: .55; pointer-events: none; }
</style>

<script>
(function () {
    'use strict';

    const importUrl = @json(route('setup.import'));
    const progressUrl = @json(route('setup.import.progress'));
    const forms = Array.from(document.querySelectorAll('form[action="' + importUrl + '"]'));
    if (!forms.length) return;

    const box = document.getElementById('ip-box');
    const phaseEl = document.getElementById('ip-phase');
    const percentEl = document.getElementById('ip-percent');
    const barEl = document.getElementById('ip-bar');
    const countEl = document.getElementById('ip-count');
    const logEl = document.getElementById('ip-log');
    const fa = (n) => Number(n).toLocaleString('fa-IR');

    let timer = null;
    let navigated = false;
    let awaiting = false;
    let prevUpdated = null;
    let lastChange = Date.now();

    const lock = () => forms.forEach((f) => f.classList.add('is-importing'));
    const unlock = () => forms.forEach((f) => f.classList.remove('is-importing'));

    function navigate(url) {
        if (navigated) return;
        navigated = true;
        setTimeout(() => { window.location.href = url; }, 350);
    }

    function paint(state) {
        box.hidden = false;
        const pct = Math.max(0, Math.min(100, Number(state.percent || 0)));
        percentEl.textContent = fa(pct) + '٪';
        barEl.style.width = pct + '%';
        phaseEl.textContent = state.phase || '—';
        if (state.total) {
            countEl.textContent = fa(state.processed || 0) + ' از ' + fa(state.total) + ' رکورد';
        } else {
            countEl.textContent = state.processed ? fa(state.processed) + ' رکورد' : '';
        }
        const lines = (state.lines || []).slice(-8);
        logEl.hidden = lines.length === 0;
        logEl.textContent = lines.join('\n');
        logEl.scrollTop = logEl.scrollHeight;
    }

    function stop() { awaiting = false; if (timer) { clearInterval(timer); timer = null; } }
    function start() { awaiting = true; lock(); if (!timer) timer = setInterval(poll, 700); }

    async function poll() {
        try {
            const res = await fetch(progressUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!res.ok) return null;
            const state = await res.json();

            if (state.updated_at !== prevUpdated) {
                prevUpdated = state.updated_at;
                lastChange = Date.now();
            }

            // Merely opening the page while a finished/stale run sits in the
            // file: observe silently — painting or navigating here would make
            // the page reload itself forever.
            if (!awaiting && !state.active) return state;

            paint(state);

            if (state.failed) {
                stop();
                unlock();
                phaseEl.textContent = state.message || 'ناموفق بود؛ خطا را بررسی کنید.';
            } else if (state.done) {
                // The response (with flash messages) is already written: follow it.
                stop();
                navigate(importUrl);
            } else if (Date.now() - lastChange > 60000) {
                // The import is quiet — a slow panel call, one big legacy table —
                // or its process died. Unlock so a retry is possible, but KEEP
                // polling: if writes resume the message is painted over by the
                // next response, and a run that finishes while this message is up
                // still navigates to its report. Stopping here would strand the
                // page on a frozen box forever (the wallets phase alone is silent
                // for ~20s while one API call runs).
                unlock();
                phaseEl.textContent = 'گزارش پیشرفت قطع شد؛ اگر ایمپورت ادامه دارد دکمه را دوباره بزنید.';
            }
            return state;
        } catch (e) {
            return null;
        }
    }

    forms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();

            paint({ percent: 0, phase: 'در حال شروع انتقال…', lines: [] });
            lastChange = Date.now();
            start();

            // NOT form.action: this form contains <input name="action">, and
            // HTMLFormElement's named-property override makes form.action the
            // INPUT element itself — the URL would stringify to
            // "/setup/[object HTMLInputElement]" and answer 405.
            fetch(importUrl, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml' },
                // manual: let the browser hold the 302 instead of following it
                // invisibly — a hidden follow-up GET would consume the flash
                // report before the visible reload could show it.
                redirect: 'manual',
                credentials: 'same-origin',
            }).then((response) => {
                if (response.type === 'opaqueredirect' || response.redirected) {
                    stop();
                    navigate(importUrl);
                    return;
                }
                if (response.status === 419) {
                    stop();
                    unlock();
                    phaseEl.textContent = 'نشان CSRF منقضی شده است؛ صفحه تازه‌سازی می‌شود.';
                    setTimeout(() => window.location.reload(), 900);
                    return;
                }
                stop();
                unlock();
                phaseEl.textContent = 'پاسخ ناموفق بود (' + response.status + ')؛ دوباره تلاش کنید.';
            }).catch(() => {
                // The connection died, not necessarily the import: the server keeps
                // running it (ignore_user_abort), so keep watching the endpoint.
            });
        });
    });

    // Re-open an import that is still running (e.g. this tab was reopened).
    poll().then((state) => { if (state && state.active) start(); });

    // A backgrounded tab gets its timers throttled (or suspended outright):
    // the moment it becomes visible again, poll immediately instead of
    // waiting for the next timer tick, so a finished import navigates to its
    // report as soon as the operator looks back.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { poll(); }
    });
})();
</script>
