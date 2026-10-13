@extends('layouts.admin', ['title' => 'تیکت‌ها', 'active' => 'tickets'])

@section('content')
    <div class="card">
        <form method="get" action="{{ route('admin.tickets.index') }}" class="row">
            <div>
                <label for="ticket-search">جستجو (موضوع یا شناسه گفتگو)</label>
                <input type="text" id="ticket-search" name="search" value="{{ $search }}"
                       placeholder="مثال: اتصال یا 123456789" autocomplete="off" spellcheck="false">
            </div>
            <div>
                <label for="ticket-status">وضعیت</label>
                <select name="status" id="ticket-status">
                    <option value="ALL" {{ $status === 'ALL' ? 'selected' : '' }}>همه تیکت‌ها</option>
                    @foreach ($statuses as $one)
                        <option value="{{ $one }}" {{ $status === $one ? 'selected' : '' }}>
                            {{ ['OPEN' => 'باز', 'ANSWERED' => 'پاسخ داده شده', 'CLOSED' => 'بسته شده'][$one] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div style="flex:0">
                <button type="submit">اعمال</button>
            </div>
        </form>
    </div>

    <div class="card">
        <p class="muted">
            {{ number_format($tickets->total()) }} تیکت یافت شد.
            @if ($openCount > 0)
                — {{ \App\Support\PersianCalendar::toPersianDigits((string) $openCount) }} تیکت باز در انتظار پاسخ
            @endif
        </p>
        <table>
            <thead>
                <tr>
                    <th>شماره تیکت</th>
                    <th>کاربر</th>
                    <th>موضوع و دپارتمان</th>
                    <th>پیام‌ها</th>
                    <th>اولویت</th>
                    <th>وضعیت</th>
                    <th>آخرین بروزرسانی</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    @php $priorityLabels = ['HIGH' => '🔴 فوری', 'MEDIUM' => '🟡 متوسط', 'LOW' => '🟢 عادی']; @endphp
                    <tr>
                        <td class="ltr">{{ $ticket->ticket_number }}</td>
                        <td>{{ $ticket->user?->name ?? ('کاربر '.$ticket->chat_id) }}</td>
                        <td>
                            {{ $ticket->subject }}
                            <span class="muted small">— {{ $ticket->department ?? 'پشتیبانی عمومی' }}</span>
                        </td>
                        <td>{{ number_format($ticket->messages_count) }}</td>
                        <td>{{ $priorityLabels[$ticket->priority] ?? $ticket->priority }}</td>
                        <td>
                            <span class="badge {{ ['OPEN' => 'ok', 'ANSWERED' => 'wait', 'CLOSED' => 'no'][$ticket->status] ?? '' }}">
                                {{ ['OPEN' => 'باز', 'ANSWERED' => 'پاسخ داده شده', 'CLOSED' => 'بسته شده'][$ticket->status] ?? $ticket->status }}
                            </span>
                        </td>
                        <td>{{ $ticket->updated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="cell-actions">
                            <a class="btn ghost" href="{{ route('admin.tickets.show', $ticket) }}">مشاهده و پاسخ</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">تیکتی در این بخش وجود ندارد.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($tickets->hasPages())
            <div class="pager">
                {{ $tickets->links() }}
            </div>
        @endif
    </div>
@endsection
