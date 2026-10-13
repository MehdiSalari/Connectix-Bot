@extends('layouts.admin', ['title' => 'تیکت '.$ticket->ticket_number, 'active' => 'tickets', 'back' => route('admin.tickets.index')])

@section('content')
    <div class="card">
        <div class="kv">
            <div><span class="muted">شماره تیکت:</span> <b class="ltr">{{ $ticket->ticket_number }}</b></div>
            <div><span class="muted">کاربر:</span> {{ $ticket->user?->name ?? 'کاربر '.$ticket->chat_id }} <span class="muted ltr">({{ $ticket->chat_id }})</span></div>
            <div><span class="muted">موضوع:</span> {{ $ticket->subject }}</div>
            <div><span class="muted">بخش:</span> {{ $ticket->department ?? 'پشتیبانی عمومی' }}</div>
            <div>
                <span class="muted">وضعیت:</span>
                <span class="badge {{ ['OPEN' => 'ok', 'ANSWERED' => 'wait', 'CLOSED' => 'no'][$ticket->status] ?? '' }}">
                    {{ ['OPEN' => 'باز', 'ANSWERED' => 'پاسخ داده شده', 'CLOSED' => 'بسته شده'][$ticket->status] ?? $ticket->status }}
                </span>
            </div>
        </div>
    </div>

    <div class="card">
        <p class="card-title">گفتگو</p>
        @forelse ($ticket->messages as $message)
            <div class="doc-block">
                <p class="muted small">
                    {{ $message->sender_type === 'ADMIN' ? 'پشتیبانی' : ($message->sender_name ?? 'کاربر') }}
                    — {{ $message->created_at?->format('Y-m-d H:i') }}
                </p>
                <p style="white-space:pre-wrap">{{ $message->message }}</p>
            </div>
        @empty
            <p class="muted">پیامی در این تیکت ثبت نشده است.</p>
        @endforelse
    </div>

    {{-- نقش‌ها همان مرز فورک را دارند: ادیتور فقط می‌خواند و فرم‌ها فقط برای
         ادمین چاپ می‌شوند — سابمیت ادیتور در مسیر ۴۰۳ می‌خورد و ساختنش فقط
         گمراه‌کننده است. --}}
    @if (auth('admin')->user()?->role?->value === 'admin')
        <div class="card">
            <p class="card-title">پاسخ و وضعیت</p>

            <form method="post" action="{{ route('admin.tickets.reply', $ticket) }}" class="form-stack">
                @csrf
                <div>
                    <label for="ticket-reply">متن پاسخ به کاربر</label>
                    <textarea id="ticket-reply" name="message" rows="4" required
                              placeholder="متن پاسخ خود را به کاربر بنویسید..."></textarea>
                </div>
                <div><button type="submit">ارسال پاسخ</button></div>
            </form>

            <div class="row row-spaced">
                <form method="post" action="{{ route('admin.tickets.status', $ticket) }}" class="inline-form">
                    @csrf
                    <label for="ticket-status">تغییر وضعیت</label>
                    <select name="status" id="ticket-status">
                        @foreach (['OPEN', 'ANSWERED', 'CLOSED'] as $one)
                            <option value="{{ $one }}" {{ $ticket->status === $one ? 'selected' : '' }}>
                                {{ ['OPEN' => 'باز', 'ANSWERED' => 'پاسخ داده شده', 'CLOSED' => 'بسته شده'][$one] }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit">اعمال</button>
                </form>

                @if ($ticket->status !== 'CLOSED')
                    <form method="post" action="{{ route('admin.tickets.close', $ticket) }}" class="inline-form"
                          data-confirm="این تیکت بسته شود؟">
                        @csrf
                        <button type="submit" class="btn danger">بستن تیکت</button>
                    </form>
                @endif
            </div>
        </div>
    @endif
@endsection
