@extends('layouts.admin', ['title' => 'کاربران', 'active' => 'users'])

@section('content')
    <div class="card">
        <form method="get" action="{{ route('admin.users.index') }}" class="row">
            <div>
                <label for="search">جستجو (شناسه گفتگو، نام یا تلگرام)</label>
                <input type="text" id="search" name="search" value="{{ $search }}" placeholder="مثال: 123456789">
            </div>
            <button type="submit" style="margin-left:0">جستجو</button>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>شناسه گفتگو</th>
                    <th>نام</th>
                    <th>تلگرام</th>
                    <th>کیف پول</th>
                    <th>اکانت تست</th>
                    <th>تاریخ عضویت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td dir="ltr">{{ $user->chat_id }}</td>
                        <td>
                            <span class="cell-user">
                                <span class="avatar">{{ mb_substr(trim((string) ($user->name ?? '')) !== '' ? (string) $user->name : (string) $user->chat_id, 0, 1) }}@if ($user->avatar)<img src="{{ $user->avatar }}" alt="" loading="lazy" onerror="this.remove()">@endif</span>
                                <span>{{ $user->name ?? '-' }}</span>
                            </span>
                        </td>
                        <td dir="ltr">{{ $user->telegram_id ?? '-' }}</td>
                        <td>{{ $user->wallet ? number_format($user->wallet->balanceAmount()) : '—' }}</td>
                        <td>{{ $user->hasUsedTest() ? 'بله' : 'خیر' }}</td>
                        <td>{{ $user->created_at?->format('Y-m-d H:i') }}</td>
                        <td><a class="btn ghost" href="{{ route('admin.users.show', $user) }}">پروفایل</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">کاربری یافت نشد.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($users->hasPages())
            <div class="pager">
                {{ $users->links() }}
            </div>
        @endif
    </div>
@endsection