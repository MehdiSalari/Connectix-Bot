@extends('layouts.admin', ['title' => 'تراکنش‌های کیف پول', 'active' => 'wallet'])

@section('content')
    <div class="card">
        <form method="get" action="{{ route('admin.wallet-transactions.index') }}" class="row">
            <div>
                <label>جستجو بر اساس شناسه گفتگو</label>
                <input type="text" name="search" value="{{ $search }}" placeholder="مثال: 123456789">
            </div>
            <button type="submit">جستجو</button>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>شناسه گفتگو</th>
                    <th>مبلغ</th>
                    <th>عملیات</th>
                    <th>نوع</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions as $tx)
                    <tr>
                        <td dir="ltr">{{ $tx->chat_id }}</td>
                        <td>{{ number_format((int) $tx->amount) }}</td>
                        <td>{{ $tx->operation->label() }}</td>
                        <td>{{ $tx->type->label() }}</td>
                        <td><span class="badge {{ $tx->status->isPending() ? 'wait' : ($tx->status->value === 'SUCCESS' ? 'ok' : 'no') }}">{{ $tx->status->labelForAdmin() }}</span></td>
                        <td>{{ $tx->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">تراکنشی یافت نشد.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($transactions->hasPages())
            <div class="pager">
                {{ $transactions->links() }}
            </div>
        @endif
    </div>
@endsection