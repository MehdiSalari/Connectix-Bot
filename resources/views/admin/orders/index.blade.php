@extends('layouts.admin', ['title' => 'سفارش‌ها', 'active' => 'orders'])

@section('content')
    <div class="card">
        <form method="get" action="{{ route('admin.orders.index') }}" class="row">
            <div>
                <label>جستجو (شماره سفارش، کاربر، مبلغ، پلن کد)</label>
                <input type="text" name="search" value="{{ $search }}" placeholder="مثال: CX25012801">
            </div>
            <div>
                <label>وضعیت</label>
                <select name="status">
                    <option value="">همه</option>
                    <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>در انتظار</option>
                    <option value="0" {{ $status === '0' ? 'selected' : '' }}>رد شده</option>
                    <option value="1" {{ $status === '1' ? 'selected' : '' }}>تایید شده</option>
                </select>
            </div>
            <div>
                <label>روش پرداخت</label>
                <select name="method">
                    <option value="">همه</option>
                    <option value="card" {{ $method === 'card' ? 'selected' : '' }}>کارت به کارت</option>
                    <option value="wallet" {{ $method === 'wallet' ? 'selected' : '' }}>کیف پول</option>
                </select>
            </div>
            <button type="submit">اعمال</button>
        </form>
    </div>

    <div class="card">
        <p class="muted">{{ number_format($total) }} سفارش یافت شد.</p>
        <table>
            <thead>
                <tr>
                    <th>شماره سفارش</th>
                    <th>کاربر</th>
                    <th>تلگرام</th>
                    <th>مبلغ</th>
                    <th>کوپن</th>
                    <th>روش</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td dir="ltr">{{ $payment->order_number }}</td>
                        <td>{{ $payment->user_name ?: ($payment->user?->name ?? '-') }}</td>
                        <td dir="ltr">{{ $payment->user_telegram ?: '-' }}</td>
                        <td>{{ number_format($payment->priceAmount()) }}</td>
                        <td dir="ltr">{{ $payment->coupon ?? '-' }}</td>
                        <td>{{ $payment->method?->label() ?? '-' }}</td>
                        <td>
                            <span class="badge {{ $payment->is_paid->isDecided() ? ($payment->is_paid->value === '1' ? 'ok' : 'no') : 'wait' }}">
                                {{ $payment->is_paid->label() }}
                            </span>
                        </td>
                        <td>{{ $payment->created_at?->format('Y-m-d H:i') }}</td>
                        <td>
                            @if ($payment->isPending())
                                <form method="post" action="{{ route('admin.orders.decide', $payment) }}" class="inline-form">
                                    @csrf
                                    <button type="submit" name="action" value="accept" class="btn">تایید</button>
                                    <button type="submit" name="action" value="reject" class="btn danger">رد</button>
                                </form>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="muted">سفارشی یافت نشد.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($pages > 1)
            <div class="pager">
                <a class="btn ghost" href="{{ route('admin.orders.index', array_merge(request()->query(), ['page' => max(1, $page - 1)])) }}">قبلی</a>
                <span>صفحه {{ $page }} از {{ $pages }}</span>
                <a class="btn ghost" href="{{ route('admin.orders.index', array_merge(request()->query(), ['page' => min($pages, $page + 1)])) }}">بعدی</a>
            </div>
        @endif
    </div>
@endsection