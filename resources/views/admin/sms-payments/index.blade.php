@extends('layouts.admin', ['title' => 'پیامک‌های بانکی', 'active' => 'sms'])

@section('content')
    <div class="card">
        <form method="get" action="{{ route('admin.sms-payments.index') }}" class="row">
            <div>
                <label>جستجو (متن پیامک، بانک یا شناسه سفارش)</label>
                <input type="text" name="search" value="{{ $search }}" placeholder="مثال: 1,200,000 ریال">
            </div>
            <button type="submit">جستجو</button>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>متن پیامک</th>
                    <th>مبلغ (تومان)</th>
                    <th>بانک</th>
                    <th>سفارش</th>
                    <th>انقضا</th>
                    <th>تاریخ</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($deposits as $deposit)
                    <tr>
                        <td dir="ltr">{{ $deposit->message }}</td>
                        <td>{{ number_format($deposit->amount) }}</td>
                        <td>{{ $deposit->bank ?? '-' }}</td>
                        <td dir="ltr">{{ $deposit->payment_id ?? '—' }}</td>
                        <td>{{ $deposit->expired_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ $deposit->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">پیامکی ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($deposits->hasPages())
            <div class="pager">
                {{ $deposits->links() }}
            </div>
        @endif
    </div>
@endsection