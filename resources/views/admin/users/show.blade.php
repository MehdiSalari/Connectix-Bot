@extends('layouts.admin', ['title' => 'پروفایل کاربر', 'active' => 'users'])

@section('content')
    <div class="card">
        {{-- عکس کاربر از پروفایل عمومی تلگرام می‌آید (users.avatar که
             connectix:sync-users از https://t.me/{username} پر می‌کند)؛ اگر
             عکسی نباشد حرف اول نام نشان داده می‌شود. --}}
        <div class="cell-user lg">
            <span class="avatar lg">{{ mb_substr(trim((string) ($user->name ?? '')) !== '' ? (string) $user->name : (string) $user->chat_id, 0, 1) }}@if ($user->avatar)<img src="{{ $user->avatar }}" alt="" loading="lazy" onerror="this.remove()">@endif</span>
            <div>
                <h2>{{ $user->name ?? 'بدون نام' }} <span class="muted">· شناسه گفتگو {{ $user->chat_id }}</span></h2>
                <p class="muted">تلگرام: {{ $user->telegram_id ?? '-' }} · عضویت: {{ $user->created_at?->format('Y-m-d H:i') }} · اکانت تست: {{ $user->hasUsedTest() ? 'استفاده شده' : 'استفاده نشده' }}</p>
            </div>
        </div>
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
                    <th>وضعیت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($user->clientsByChatId as $client)
                    <tr data-client-details="{{ $client->id }}"
                        data-client-label="{{ $client->username ? '@'.$client->username : $client->id }}">
                        <td dir="ltr">{{ $client->id }}</td>
                        <td dir="ltr">{{ $client->username ?? '-' }}</td>
                        <td>{{ $client->count_of_devices ?? '-' }}</td>
                        <td>{{ $client->created_at?->format('Y-m-d H:i') }}</td>
                        <td>
                            {{-- فعال/در صف/غیرفعال از وضعیت پلن پنل می‌آید (همان
                                 قاعده‌ای که ربات قدیمی استفاده می‌کرد) و تا وقتی
                                 پلن‌ها خوانده نشده‌اند از پنجره‌ی پایان اشتراک؛
                                 اگر هیچ‌کدام معلوم نباشد نامشخص، نه حدس. --}}
                            <span class="badge {{ $client->statusBadge()['tone'] }}"@if($client->expire_date) title="پایان اشتراک: {{ $client->expire_date }}"@endif>{{ $client->statusBadge()['label'] }}</span>
                        </td>
                        <td class="cell-actions">
                            <button type="button" class="btn ghost client-details"
                                    data-client-details="{{ $client->id }}"
                                    data-client-label="{{ $client->username ? '@'.$client->username : $client->id }}">جزئیات</button>
                            @if (auth('admin')->user()?->isAdmin())
                                <form method="post" action="{{ route('admin.clients.destroy', $client) }}" class="inline-form"
                                      onsubmit="return confirm('این اکانت از دیتابیس و از پنل Connectix حذف شود؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn danger">حذف</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">اکانتی ثبت نشده است.</td></tr>
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

    {{-- مودال جزئیات اکانت از layouts/admin می‌آید (partials.client-details) و
         همین‌جا با data-client-details روی هر سطر/دکمه باز می‌شود. --}}
@endsection