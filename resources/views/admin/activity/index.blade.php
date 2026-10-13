@extends('layouts.admin', ['title' => 'فعالیتها', 'active' => 'activity'])

@section('content')
    <div class="card">
        <p class="muted small">
            در این بخش فقط کارهایی که خودتان در پنل انجام دادهاید دیده میشود —
            قاعدهٔ فورک: «هر ادمین فقط فعالیتهای خودش را میبیند».
        </p>
        <form method="get" action="{{ route('admin.activity.index') }}" class="row mt-3">
            <div>
                <label for="activity-search">جستجو در عمل</label>
                <input type="search" id="activity-search" name="search" value="{{ $search }}"
                       placeholder="مثال: admin_create" autocomplete="off" spellcheck="false">
            </div>
            <div style="flex:0">
                <button type="submit">اعمال</button>
            </div>
        </form>
    </div>

    <div class="card">
        <p class="muted">{{ number_format($activities->total()) }} مورد یافت شد.</p>
        <table>
            <thead>
                <tr>
                    <th>زمان</th>
                    <th>عمل</th>
                    <th>جزئیات</th>
                    <th>آیپی</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($activities as $row)
                    <tr>
                        <td class="ltr">{{ $row->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td>
                            <span class="badge">{{ $labels[$row->action] ?? $row->action }}</span>
                        </td>
                        <td class="ltr small">{{ $row->details ?? '—' }}</td>
                        <td class="ltr small">{{ $row->ip ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">هنوز فعالیتی ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($activities->hasPages())
            <div class="pager">
                {{ $activities->links() }}
            </div>
        @endif
    </div>
@endsection
