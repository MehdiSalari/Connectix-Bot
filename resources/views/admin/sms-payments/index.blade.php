@extends('layouts.admin', ['title' => 'پیامک‌های بانکی', 'active' => 'sms'])

@section('content')
    <div class="card">
        {{-- جستجو و فیلترها با هم یک فرم GET‌اند تا نتیجه با آدرس هم قابل
             اشتراک باشد و بدون جاوااسکریپت هم کار کند.

             جستجو متن پیام، مبلغ، بانک و شناسه سفارش را می‌گیرد؛ مبلغ به
             هر شکلی که تایپ شده (لاتین یا فارسی) با ستون amount می‌نشیند،
             چون سمت سرور ارقام هر دو شکل به هم تبدیل می‌شوند. --}}
        <form method="get" action="{{ route('admin.sms-payments.index') }}" class="row">
            <div>
                <label for="sms-search">جستجو (متن پیام، مبلغ، بانک یا شناسه سفارش)</label>
                <input type="text" id="sms-search" name="search" value="{{ $search }}"
                       placeholder="مثال: 556,000">
            </div>
            <div>
                <label for="sms-bank">بانک</label>
                <select name="bank" id="sms-bank">
                    <option value="">همه بانک‌ها</option>
                    @foreach ($banks as $option)
                        <option value="{{ $option }}" @selected($bank === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="sms-from">از تاریخ</label>
                <input type="date" id="sms-from" name="from" value="{{ $from }}">
            </div>
            <div>
                <label for="sms-to">تا تاریخ</label>
                <input type="date" id="sms-to" name="to" value="{{ $to }}">
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <button type="submit">اعمال</button>
                @if ($search !== '' || $bank !== '' || $from !== null || $to !== null)
                    <a class="btn ghost" href="{{ route('admin.sms-payments.index') }}">پاک کردن</a>
                @endif
            </div>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>مبلغ (تومان)</th>
                    <th>بانک</th>
                    <th>نوع پرداخت</th>
                    <th>وضعیت</th>
                    <th>انقضا</th>
                    <th>تاریخ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($deposits as $deposit)
                    {{-- متن پیام عمداً این نیست: سطر را شلوغ می‌کرد و خواندنِ
                         ستون‌های عددی را سخت. متن کامل در همین سطر است —
                         کلیک روی هر جای آن (یا دکمه‌ی «جزئیات») پنجره‌ی
                         جزئیات را باز می‌کند که چهار کارت دارد: اطلاعات
                         واریز، اطلاعات کاربر، متن پیام و جزئیات خرید. --}}
                    @php [$statusLabel, $statusTone] = $deposit->status(); @endphp
                    <tr data-sms-details="{{ $deposit->id }}"
                        data-sms-label="واریز {{ number_format($deposit->amount) }} تومان">
                        <td>{{ number_format($deposit->amount) }}</td>
                        <td>{{ $deposit->bank ?? '-' }}</td>
                        <td>{{ $deposit->typeLabel() ?? '—' }}</td>
                        <td><span class="badge {{ $statusTone }}">{{ $statusLabel }}</span></td>
                        <td>{{ $deposit->expired_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ $deposit->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="cell-actions">
                            <button type="button" class="btn ghost"
                                    data-sms-details="{{ $deposit->id }}">جزئیات</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">{{ ($search !== '' || $bank !== '' || $from !== null || $to !== null) ? 'پیامکی با این فیلترها پیدا نشد.' : 'پیامکی ثبت نشده است.' }}</td></tr>
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
