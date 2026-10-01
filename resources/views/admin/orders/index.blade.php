@extends('layouts.admin', ['title' => 'سفارش‌ها', 'active' => 'orders'])

@section('content')
    <div class="card">
        <form method="get" action="{{ route('admin.orders.index') }}" class="row">
            <div>
                <label for="order-search">جستجو (شماره سفارش، کاربر، مبلغ، پلن کد)</label>
                <input type="text" id="order-search" name="search" value="{{ $search }}" placeholder="مثال: CX25012801">
            </div>
            <div>
                <label for="order-status">وضعیت</label>
                <select name="status" id="order-status">
                    <option value="">همه</option>
                    <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>در انتظار</option>
                    <option value="0" {{ $status === '0' ? 'selected' : '' }}>رد شده</option>
                    <option value="1" {{ $status === '1' ? 'selected' : '' }}>تایید شده</option>
                </select>
            </div>
            <div>
                <label for="order-method">روش پرداخت</label>
                <select name="method" id="order-method">
                    <option value="">همه</option>
                    <option value="card" {{ $method === 'card' ? 'selected' : '' }}>کارت به کارت</option>
                    <option value="wallet" {{ $method === 'wallet' ? 'selected' : '' }}>کیف پول</option>
                </select>
            </div>
            <div style="flex:0">
                <button type="submit">اعمال</button>
            </div>
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
                        <td class="ltr">{{ $payment->order_number }}</td>
                        <td>{{ $payment->user_name ?: ($payment->user?->name ?? '-') }}</td>
                        <td class="ltr">{{ $payment->user_telegram ?: '—' }}</td>
                        <td>{{ number_format($payment->priceAmount()) }}</td>
                        <td class="ltr">{{ $payment->coupon ?? '—' }}</td>
                        <td>{{ $payment->method?->label() ?? '—' }}</td>
                        <td>
                            <span class="badge {{ $payment->is_paid->isDecided() ? ($payment->is_paid->value === '1' ? 'ok' : 'no') : 'wait' }}">
                                {{ $payment->is_paid->label() }}
                            </span>
                        </td>
                        <td>{{ $payment->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="cell-actions">
                            {{-- جزئیات سفارش در همان مودالی باز می‌شود که جزئیات اکانت
                                 را نشان می‌دهد: هر دو از همان پنل خوانده می‌شوند و
                                 هر دو فقط-خواندنی هستند. --}}
                            <button type="button" class="btn ghost"
                                    data-order-details="{{ $payment->id }}"
                                    data-order-label="{{ $payment->order_number }}">جزئیات</button>

                            @if ($payment->isPending())
                                <form method="post" action="{{ route('admin.orders.decide', $payment) }}" class="inline-form">
                                    @csrf
                                    <button type="submit" name="action" value="accept" class="btn">تایید</button>
                                    <button type="submit" name="action" value="reject" class="btn danger">رد</button>
                                </form>
                            @endif
                            {{-- سفارش تعیین‌شده‌شده فقط دکمه‌ی «جزئیات» دارد؛ علامت
                                 «—» یک جای‌خالیِ تزئینی بود که در کنار دکمه
                                 مثل یک غیب‌شدنِ داده خوانده می‌شد. --}}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="muted">سفارشی یافت نشد.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($payments->hasPages())
            <div class="pager">
                {{ $payments->links() }}
            </div>
        @endif
    </div>

    {{-- مودال جزئیات سفارش از layouts/admin می‌آید و با data-order-details روی
         هر دکمه‌ی «جزئیات» باز می‌شود. --}}
@endsection
