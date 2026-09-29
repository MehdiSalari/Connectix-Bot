<table>
    <tr>
        <th style="width: 40%">جدول</th>
        <th>وضعیت</th>
    </tr>
    @foreach (config('setup.tables', []) as $table)
        <tr>
            <td><code>{{ $table }}</code></td>
            <td>
                @if (\Illuminate\Support\Facades\Schema::hasTable($table))
                    موجود
                @else
                    ساخته می‌شود
                @endif
            </td>
        </tr>
    @endforeach
</table>

@if ($stepData['pending'] !== [])
    <div class="alert warn">
        این migration ها هنوز اجرا نشده‌اند:
        <ul class="plain">
            @foreach ($stepData['pending'] as $migration)
                <li><code>{{ $migration }}</code></li>
            @endforeach
        </ul>
    </div>
@endif

<div class="alert warn">
    فقط migration های اجرانشده اجرا می‌شوند. دستورهای <code>migrate:fresh</code>، <code>migrate:reset</code>،
    <code>db:wipe</code> و هر دستور حذف داده‌ای در این نصب‌کننده وجود ندارد.
</div>

<form method="post" action="{{ route('setup.migrations') }}">
    @csrf
    <div class="actions">
        <button type="submit">ساخت جدول‌های لازم</button>
        <a href="{{ route('setup.show', ['step' => $step->previous()->value]) }}">
            <button type="button" class="secondary">مرحله قبل</button>
        </a>
    </div>
</form>
