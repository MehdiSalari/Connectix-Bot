@extends('layouts.admin', ['title' => 'پروفایل کاربر', 'active' => 'users'])

@section('content')
    <div class="card">
        <h2>{{ $user->name ?? 'بدون نام' }} <span class="muted">· شناسه گفتگو {{ $user->chat_id }}</span></h2>
        <p class="muted">تلگرام: {{ $user->telegram_id ?? '-' }} · عضویت: {{ $user->created_at?->format('Y-m-d H:i') }} · اکانت تست: {{ $user->hasUsedTest() ? 'استفاده شده' : 'استفاده نشده' }}</p>
    </div>

    <div class="card">
        <h2>کیف پول</h2>
        <p>موجودی: <strong>{{ number_format($user->wallet?->balanceAmount() ?? 0) }}</strong> تومان</p>

        <div class="row">
            <form method="post" action="{{ route('admin.users.wallet.create') }}" class="inline-form">
                @csrf
                <input type="hidden" name="chat_id" value="{{ $user->chat_id }}">
                <button type="submit" class="btn ghost">ساخت کیف پول</button>
            </form>

            <form method="post" action="{{ route('admin.users.wallet.adjust') }}" class="row" style="flex:2; gap:10px">
                @csrf
                <input type="hidden" name="chat_id" value="{{ $user->chat_id }}">
                <div>
                    <label>عملیات</label>
                    <select name="operation">
                        <option value="INCREASE">افزایش</option>
                        <option value="DECREASE">کاهش</option>
                    </select>
                </div>
                <div>
                    <label>مبلغ (تومان)</label>
                    <input type="number" name="amount" min="1" required>
                </div>
                <div style="flex:0">
                    <label>
                        <input type="checkbox" name="announce" value="1" checked> اطلاع به کاربر
                    </label>
                </div>
                <div>
                    <button type="submit">اعمال</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>اکانت‌های Connectix</h2>
        <table>
            <thead>
                <tr>
                    <th>شناسه</th>
                    <th>نام کاربری</th>
                    <th>تعداد دستگاه</th>
                    <th>تاریخ ساخت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($user->clientsByChatId as $client)
                    <tr>
                        <td dir="ltr">{{ $client->id }}</td>
                        <td dir="ltr">{{ $client->username ?? '-' }}</td>
                        <td>{{ $client->count_of_devices ?? '-' }}</td>
                        <td>{{ $client->created_at?->format('Y-m-d H:i') }}</td>
                        <td><button type="button" class="btn ghost client-details" data-id="{{ $client->id }}">جزئیات</button></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">اکانتی ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>سفارش‌ها</h2>
        <table>
            <thead>
                <tr>
                    <th>شماره سفارش</th>
                    <th>مبلغ</th>
                    <th>روش</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($user->payments as $payment)
                    <tr>
                        <td dir="ltr">{{ $payment->order_number }}</td>
                        <td>{{ number_format($payment->priceAmount()) }}</td>
                        <td>{{ $payment->method->label() }}</td>
                        <td><span class="badge {{ $payment->is_paid->isDecided() ? ($payment->is_paid->value === '1' ? 'ok' : 'no') : 'wait' }}">{{ $payment->is_paid->label() }}</span></td>
                        <td>{{ $payment->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">سفارشی ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>تراکنش‌های کیف پول</h2>
        <table>
            <thead>
                <tr>
                    <th>مبلغ</th>
                    <th>عملیات</th>
                    <th>نوع</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($user->walletTransactions as $tx)
                    <tr>
                        <td>{{ number_format((int) $tx->amount) }}</td>
                        <td>{{ $tx->operation->label() }}</td>
                        <td>{{ $tx->type->label() }}</td>
                        <td><span class="badge {{ $tx->status->isPending() ? 'wait' : ($tx->status->value === 'SUCCESS' ? 'ok' : 'no') }}">{{ $tx->status->labelForAdmin() }}</span></td>
                        <td>{{ $tx->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">تراکنشی ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div id="client-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); align-items:center; justify-content:center; z-index:50">
        <div class="card" style="max-width:640px; width:90%; margin:0">
            <h2>جزئیات اکانت</h2>
            <pre id="client-detail" style="white-space:pre-wrap; direction:ltr; text-align:left; font-size:13px; max-height:60vh; overflow:auto">در حال بارگذاری...</pre>
            <button type="button" id="client-modal-close" class="btn ghost">بستن</button>
        </div>
    </div>

    <script>
        (function () {
            const modal = document.getElementById('client-modal');
            const detail = document.getElementById('client-detail');

            document.querySelectorAll('.client-details').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    modal.style.display = 'flex';
                    detail.textContent = 'در حال بارگذاری...';

                    fetch("{{ route('admin.clients.show', ':id') }}".replace(':id', encodeURIComponent(btn.dataset.id)))
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.error) { detail.textContent = data.error; return; }
                            detail.textContent = JSON.stringify(data, null, 2);
                        })
                        .catch(function () { detail.textContent = 'خطا در دریافت اطلاعات.'; });
                });
            });

            document.getElementById('client-modal-close').addEventListener('click', function () {
                modal.style.display = 'none';
            });
        })();
    </script>
@endsection