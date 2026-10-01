@extends('layouts.admin', [
    'title' => 'پروفایل کاربر',
    'active' => 'users',
    // این صفحه در منوی کناری ردیفی ندارد؛ دکمه‌ی بازگشت توپار آن را به
    // فهرست کاربران برمی‌گرداند، جایی که معمولاً از آن باز شده است.
    'back' => route('admin.users.index'),
])

@section('content')
    <div class="card">
        {{-- عکس کاربر از پروفایل عمومی تلگرام می‌آید (users.avatar که
             connectix:sync-users از https://t.me/{username} پر می‌کند)؛ اگر
             عکسی نباشد حرف اول نام نشان داده می‌شود.

             کلیک روی عکس آن را بزرگ می‌کند (data-photo). دکمه‌ی
             «جزئیات کاربر» دیگر لازم نیست: این صفحه خودش جزئیات کاربر
             است. دکمه‌ی تاریخچه هم به بخش «کیف پول» پایین‌تر رفته. --}}
        <div class="cell-user lg">
            @php $photoUserName = $user->name ?: 'کاربر '.$user->chat_id; @endphp
            <span class="avatar lg{{ $user->avatar ? ' avatar-btn' : '' }}"
                  @if ($user->avatar)
                      data-photo="{{ $user->avatar }}"
                      data-photo-name="{{ $photoUserName }}"
                      role="button"
                      tabindex="0"
                      aria-label="بزرگ‌نمایی عکس {{ $photoUserName }}"
                  @endif
            >{{ mb_substr(trim((string) ($user->name ?? '')) !== '' ? (string) $user->name : (string) $user->chat_id, 0, 1) }}@if ($user->avatar)<img class="avatar-photo" src="{{ $user->avatar }}" alt="" loading="lazy" onerror="this.remove()">@endif</span>
            <div>
                <h2>{{ $user->name ?? 'بدون نام' }} <span class="muted">· شناسه گفتگو {{ $user->chat_id }}</span></h2>
                <p class="muted">تلگرام: @if (filled($user->telegram_id))<a href="https://t.me/{{ ltrim($user->telegram_id, '@') }}" target="_blank" rel="noopener" dir="ltr">{{ $user->telegram_id }}</a>@else-@endif · عضویت: {{ $user->created_at?->format('Y-m-d H:i') }} · اکانت تست: {{ $user->hasUsedTest() ? 'استفاده شده' : 'استفاده نشده' }}</p>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>کیف پول</h2>
        @if ($user->wallet)
            <p>موجودی: <strong>{{ number_format($user->wallet->balanceAmount()) }}</strong> تومان</p>
        @else
            <p class="muted">هنوز کیف پولی برای این کاربر ساخته نشده. اولین تغییر موجودی آن را می‌سازد.</p>
        @endif

        {{-- یک اکشن، نه دو: قبلاً «ساخت کیف پول» و «اعمال» دو دکمه جدا بودند و
             تا وقتی اولی زده نشده بود دومی خطای «کیف پول پیدا نشد» می‌داد.
             حالا خودِ اعمال، کیف پول را در صورت نبودن می‌سازد.

             دکمه‌ی «تاریخچه کیف پول» هم کنار «اعمال» آمده (داخل همان سلول،
             بعد از آن، پس در چیدمان راست‌به‌چپ سمت چپِ «اعمال» می‌نشیند) تا
             هر دو اکشنِ این بخش در یک نقطه باشند. type="button" است و فرم را
             ارسال نمی‌کند. --}}
        <form method="post" action="{{ route('admin.users.wallet.adjust') }}" class="row">
            @csrf
            <input type="hidden" name="chat_id" value="{{ $user->chat_id }}">
            <div>
                <label for="wallet-operation">عملیات</label>
                <select name="operation" id="wallet-operation">
                    <option value="INCREASE">افزایش</option>
                    <option value="DECREASE">کاهش</option>
                </select>
            </div>
            <div>
                <label for="wallet-amount">مبلغ (تومان)</label>
                <input type="number" name="amount" id="wallet-amount" min="1" required inputmode="numeric">
            </div>
            <div style="flex:0">
                <label for="wallet-announce">
                    <input type="checkbox" name="announce" id="wallet-announce" value="1" checked> اطلاع به کاربر
                </label>
            </div>
            <div style="flex:0; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                <button type="submit">اعمال</button>
                <button type="button" class="btn ghost"
                        data-wallet-history="{{ $user->chat_id }}"
                        data-wallet-label="{{ $photoUserName }}">تاریخچه کیف پول</button>
            </div>
        </form>
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
                        data-client-label="{{ $client->username ?: $client->id }}">
                        <td class="ltr">{{ $client->id }}</td>
                        <td class="ltr">{{ $client->username ?? '-' }}</td>
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
                            <button type="button" class="btn ghost"
                                    data-client-details="{{ $client->id }}"
                                    data-client-label="{{ $client->username ?: $client->id }}">جزئیات</button>
                            @if (auth('admin')->user()?->isAdmin())
                                <form method="post" action="{{ route('admin.clients.destroy', $client) }}" class="inline-form"
                                      data-confirm="این اکانت از دیتابیس و از پنل Connectix حذف می‌شود. این کار برگشت‌پذیر نیست."
                                      data-confirm-title="حذف اکانت"
                                      data-confirm-accept="حذف کن">
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
                        <td class="ltr">{{ $payment->order_number }}</td>
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
                        {{-- رنگ و فلش جهت را می‌گویند تا مبلغ بدون خواندن کلمه‌ی
                             «افزایش»/«کاهش» هم خوانده شود. --}}
                        <td>
                            <span class="{{ $tx->operation === \App\Enums\WalletOperation::Increase ? 'money-up' : 'money-down' }}">
                                {{ number_format((int) $tx->amount) }}
                            </span>
                            <span class="muted">تومان</span>
                        </td>
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

        @if ($user->walletTransactions->total() > $user->walletTransactions->count())
            <div class="actions">
                <button type="button" class="btn ghost"
                        data-wallet-history="{{ $user->chat_id }}"
                        data-wallet-label="{{ $photoUserName }}">مشاهده همه {{ number_format($user->walletTransactions->total()) }} تراکنش</button>
            </div>
        @endif
    </div>

    {{-- مودال جزئیات اکانت از layouts/admin می‌آید (partials.client-details) و
         همین‌جا با data-client-details روی هر سطر/دکمه باز می‌شود. --}}
@endsection