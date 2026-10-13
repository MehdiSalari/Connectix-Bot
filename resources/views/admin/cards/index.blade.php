@extends('layouts.admin', ['title' => 'کارت‌های مشکوک', 'active' => 'cards'])

@section('content')
    <div class="card">
        <div class="row row-spaced">
            <div>
                <h2>کارت‌های مشکوک</h2>
                <p class="muted">کارت‌هایی که با چند آیدی استفاده شده‌اند و کاربرانی که با چند کارت واریز کرده‌اند</p>
            </div>
        </div>
        <div class="row row-spaced">
            <div>
                <label>کارت‌های یکتا</label>
                <p class="strong">{{ number_format($stats['total_cards']) }}</p>
            </div>
            <div>
                <label>کاربران پرچم‌دار</label>
                <p class="strong">{{ number_format($stats['flagged']) }}</p>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="row row-spaced">
            <div>
                <h2>کاربران با {{ $threshold }} کارت مختلف یا بیشتر</h2>
                <p class="muted">در ۳۰ روز گذشته؛ هر ردیف با کلیک، کارت‌هایش را نشان می‌دهد.</p>
            </div>
            <div>
                <form method="post" action="{{ route('admin.cards.threshold') }}" class="row">
                    @csrf
                    <div>
                        <label for="card-threshold">آستانهٔ هشدار:</label>
                        <input type="number" id="card-threshold" name="threshold" min="2" max="20"
                               value="{{ $threshold }}" class="ltr" style="width:90px">
                    </div>
                    <button type="submit" style="margin-left:0">ذخیره</button>
                </form>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>آیدی کاربر</th>
                    <th>تعداد کارت</th>
                    <th>دفعات واریز</th>
                    <th>آخرین استفاده</th>
                    <th>کارت‌ها</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($flagged as $row)
                    <tr>
                        <td class="ltr">{{ $row['chat_id'] }}</td>
                        <td class="strong">{{ number_format($row['cards_count']) }}</td>
                        <td>{{ number_format($row['uses']) }}</td>
                        <td>{{ $row['last_seen']?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td>
                            {{-- فهرست کارت‌ها با <details> باز می‌شود: بدون
                                 جاوااسکریپت، و بدون سطرِ دومِ همیشه‌نمایان --}}
                            <details>
                                <summary>{{ count($row['cards']) }} کارت</summary>
                                <ul class="ltr" style="direction:ltr; text-align:left; margin:8px 0 0; padding-inline-start:18px">
                                    @foreach ($row['cards'] as $card)
                                        <li>{{ trim(chunk_split($card, 4, ' ')) }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">موردی نیست.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>کارت‌های مشترک بین چند آیدی</h2>
        <table>
            <thead>
                <tr>
                    <th>کارت</th>
                    <th>تعداد آیدی</th>
                    <th>دفعات</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stats['per_card'] as $card)
                    @if ($card['users'] < 2)
                        @continue
                    @endif
                    <tr>
                        <td class="ltr">{{ trim(chunk_split($card['card'], 4, ' ')) }}</td>
                        <td class="strong">{{ number_format($card['users']) }}</td>
                        <td>{{ number_format($card['uses']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">کارت مشترکی ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if (collect($stats['per_card'])->every(fn (array $card): bool => $card['users'] < 2))
            <p class="muted">کارت مشترکی ثبت نشده است.</p>
        @endif
    </div>
@endsection
