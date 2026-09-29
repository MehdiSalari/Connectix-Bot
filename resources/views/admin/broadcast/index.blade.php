@extends('layouts.admin', ['title' => 'پیام همگانی', 'active' => 'broadcast'])

@section('content')
    <div class="card">
        <h2>ارسال پیام همگانی</h2>

        @if (! empty($running))
            <div class="flash warning">
                یک ارسال در حال اجراست ({{ number_format($sent ?? 0) }} نفر ارسال شده). صبر کنید تا تمام شود یا صفحه را رفرش کنید.
            </div>
        @endif

        <form id="broadcast-form" method="post" action="{{ route('admin.broadcast.start') }}" enctype="multipart/form-data">
            @csrf
            <div>
                <label>متن پیام</label>
                <textarea name="message" required placeholder="متن پیام به همه کاربران..."></textarea>
            </div>
            <div>
                <label>رسانه (اختیاری)</label>
                <input type="file" name="media">
            </div>
            <div>
                <label>
                    <input type="checkbox" name="test" value="1" id="broadcast-test">
                    حالت تست — فقط برای خودم ارسال شود
                </label>
            </div>
            <div style="margin-top:16px">
                <button type="submit" id="broadcast-send">شروع ارسال</button>
            </div>
        </form>
    </div>

    <div class="card" id="broadcast-log-card" style="display:none">
        <h2>گزارش ارسال</h2>
        <p id="broadcast-counter" class="muted"></p>
        <div id="broadcast-log" style="font-size:13px; max-height:320px; overflow:auto; direction:ltr; text-align:left">
        </div>
    </div>

    <script>
        (function () {
            const form = document.getElementById('broadcast-form');
            const sendBtn = document.getElementById('broadcast-send');
            const logCard = document.getElementById('broadcast-log-card');
            const log = document.getElementById('broadcast-log');
            const counter = document.getElementById('broadcast-counter');

            form.addEventListener('submit', function (evt) {
                evt.preventDefault();

                sendBtn.disabled = true;
                sendBtn.textContent = 'در حال شروع...';

                const fd = new FormData(form);
                fd.set('test', document.getElementById('broadcast-test').checked ? '1' : '0');

                fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        logCard.style.display = 'block';
                        if (! data.ok) { appendLog('خطا در شروع ارسال', 'error'); sendBtn.disabled = false; sendBtn.textContent = 'شروع ارسال'; return; }

                        const es = new EventSource(data.progress_url);

                        es.addEventListener('progress', function (event) {
                            const p = JSON.parse(event.data);
                            counter.textContent = 'ارسال شده: ' + p.done + ' از ' + p.total;
                        });

                        es.addEventListener('log', function (event) {
                            const item = JSON.parse(event.data);
                            appendLog(item.chat_id + ' | ' + item.message, item.status);
                        });

                        es.addEventListener('done', function () {
                            appendLog('پایان ارسال.', 'done');
                            es.close();
                            setTimeout(function () { window.location.reload(); }, 800);
                        });

                        es.onerror = function () {
                            es.close();
                            appendLog('اتصال قطع شد. صفحه رفرش شود.', 'error');
                            sendBtn.disabled = false;
                            sendBtn.textContent = 'شروع ارسال';
                        };
                    })
                    .catch(function () {
                        appendLog('خطا در شروع ارسال.', 'error');
                        sendBtn.disabled = false;
                        sendBtn.textContent = 'شروع ارسال';
                    });
            });

            function appendLog(text, status) {
                const el = document.createElement('div');
                el.style.padding = '2px 0';
                el.style.color = status === 'success' || status === 'done' ? '#146b38' : (status === 'error' ? '#9c2b25' : '#333');
                el.textContent = (status === 'success' ? '✓ ' : status === 'error' ? '✗ ' : '') + text;
                log.appendChild(el);
                log.scrollTop = log.scrollHeight;
            }
        })();
    </script>
@endsection