@extends('layouts.admin', ['title' => 'داشبورد', 'active' => 'dashboard'])

@section('content')
    {{-- عکس برنامه: مثل پنل قدیمی، از صفحه‌ی عمومی t.me خودِ ربات گرفته می‌شود
         (TelegramProfileService::botProfile آن را کش می‌کند). --}}
    <div class="card">
        <div class="cell-user lg">
            <span class="avatar lg">{{ mb_substr(trim((string) $appName) !== '' ? (string) $appName : 'R', 0, 1) }}@if ($bot['avatar'] ?? null)<img src="{{ $bot['avatar'] }}" alt="" loading="lazy" onerror="this.remove()">@endif</span>
            <div>
                <h2>پنل مدیریت {{ $appName }}</h2>
                <p class="muted">@if (! empty($bot['username']))<span dir="ltr">&#64;{{ $bot['username'] }}</span> · @endifخوش آمدید{{ isset($admin?->email) ? '، '.$admin->email : '' }}</p>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="stat">
            <div class="k">کاربران</div>
            <div class="v">{{ number_format($stats['users']) }}</div>
        </div>
        <div class="stat">
            <div class="k">سفارش‌های امروز</div>
            <div class="v">{{ number_format($stats['today_payments']) }}</div>
        </div>
        <div class="stat">
            <div class="k">فروش امروز (تومان)</div>
            <div class="v">{{ number_format($stats['today_sum']) }}</div>
        </div>
        <div class="stat">
            <div class="k">سفارش‌های در انتظار بررسی</div>
            <div class="v">{{ number_format($stats['pending_payments']) }}</div>
        </div>
        <div class="stat">
            <div class="k">موجودی کل کیف پول‌ها</div>
            <div class="v">{{ number_format($stats['wallet_balance']) }}</div>
        </div>
    </div>

    <div class="card">
        <h2>آخرین کاربران</h2>
        <table>
            <thead>
                <tr>
                    <th>شناسه گفتگو</th>
                    <th>نام</th>
                    <th>تلگرام</th>
                    <th>تاریخ عضویت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recent_users as $user)
                    <tr>
                        <td class="ltr">{{ $user->chat_id }}</td>
                        <td>{{ $user->name ?? '-' }}</td>
                        <td class="ltr">{{ $user->telegram_id ?? '-' }}</td>
                        <td>{{ $user->created_at?->format('Y-m-d H:i') }}</td>
                        <td><a class="btn ghost" href="{{ route('admin.users.show', $user) }}">مشاهده</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">کاربری ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>آخرین سفارش‌ها</h2>
        <table>
            <thead>
                <tr>
                    <th>شماره سفارش</th>
                    <th>کاربر</th>
                    <th>مبلغ</th>
                    <th>روش</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recent_payments as $payment)
                    <tr>
                        <td class="ltr">{{ $payment->order_number }}</td>
                        <td>@if ($payment->user?->name) {{ $payment->user->name }} @else <span class="muted">-</span> @endif</td>
                        <td>{{ number_format($payment->priceAmount()) }}</td>
                        <td>{{ $payment->method->label() }}</td>
                        <td><span class="badge {{ $payment->is_paid->isDecided() ? ($payment->is_paid->value === '1' ? 'ok' : 'no') : 'wait' }}">{{ $payment->is_paid->label() }}</span></td>
                        <td>{{ $payment->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">سفارشی ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection