@extends('layouts.admin')

@section('content')
<div class="admin-page">

    <div class="page-header">
        <h1>{{ $title }}</h1>
        <p class="page-desc">ریجستری ایموجی‌ها: دکمه‌ها، پیام‌ها، پلن‌ها، پرداخت، پشتیبانی، نمایندگی و سفارشی + پک‌های پرمیوم واردشده.</p>
    </div>

    <div class="card">
        <div class="card-header">
            <ul class="tabs" role="tablist">
                @foreach ($sections as $key => $label)
                    <li role="presentation">
                        <a href="{{ route('admin.emojis.index', ['section' => $key]) }}"
                           role="tab"
                           aria-selected="{{ $section === $key }}"
                           class="{{ $section === $key ? 'active' : '' }}">
                            {{ $label }}
                        </a>
                    </li>
                @endforeach
                <li role="presentation">
                    <a href="{{ route('admin.emojis.index', ['section' => 'packs']) }}"
                       role="tab"
                       aria-selected="{{ $section === 'packs' }}"
                       class="{{ $section === 'packs' ? 'active' : '' }}">
                        پک‌های واردشده
                    </a>
                </li>
            </ul>
        </div>

        <div class="card-body">
            @if ($section === 'packs')
                {{-- IMPORTED PACKS TAB --}}
                <div class="packs-section">
                    <div class="packs-header">
                        <h3>پک‌های ایموجی پرمیوم واردشده</h3>
                        <button type="button" class="btn btn-primary" data-modal-open="import-pack-modal">وارد کردن پک جدید</button>
                    </div>

                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>نام پک</th>
                                    <th>تعداد ایموجی</th>
                                    <th>آخرین ورود</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($packs as $pack)
                                    <tr>
                                        <td>{{ $pack['set_name'] }}</td>
                                        <td>{{ $pack['cnt'] }}</td>
                                        <td>
                                            @php
                                                $emojis = $packEmojis[$pack['set_name']] ?? [];
                                                $last = $emojis ? max(array_column($emojis, 'imported_at')) : null;
                                            @endphp
                                            {{ $last ? \Carbon\Carbon::parse($last)->format('Y/m/d H:i') : '—' }}
                                        </td>
                                        <td>
                                            <button type="button"
                                                    class="btn btn-sm btn-secondary"
                                                    data-modal-open="pack-emojis-{{ $pack['set_name'] }}">
                                                مشاهده ایموجی‌ها
                                            </button>
                                            <form method="POST" action="{{ route('admin.emojis.delete-pack') }}" class="inline-form"
                                                  onsubmit="return confirm('کل پک حذف شود؟ ایموجی‌های ربات که از این پک استفاده می‌کنند دست‌نخورده می‌مانند.');">
                                                @csrf
                                                <input type="hidden" name="set_name" value="{{ $pack['set_name'] }}">
                                                <button type="submit" class="btn btn-sm btn-danger">حذف پک</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- MODALS: one per pack for its emojis with favourite stars --}}
                    @foreach ($packs as $pack)
                        @php
                            $emojis = $packEmojis[$pack['set_name']] ?? [];
                        @endphp
                        @if (! empty($emojis))
                            <div class="modal" id="pack-emojis-{{ $pack['set_name'] }}" role="dialog" aria-modal="true" aria-labelledby="pack-emojis-{{ $pack['set_name'] }}-title">
                                <div class="modal-overlay" data-modal-close></div>
                                <div class="modal-box">
                                    <div class="modal-header">
                                        <h4 id="pack-emojis-{{ $pack['set_name'] }}-title">ایموجی‌های پک «{{ $pack['set_name'] }}»</h4>
                                        <button type="button" class="modal-close" data-modal-close aria-label="بستن">×</button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="pack-emoji-grid">
                                            @foreach ($emojis as $emoji)
                                                <div class="pack-emoji-card {{ $emoji->is_favorite ? 'favorite' : '' }}">
                                                    <span class="emoji-char">{{ $emoji->emoji_char }}</span>
                                                    <span class="emoji-id">{{ $emoji->custom_emoji_id }}</span>
                                                    <form method="POST" action="{{ route('admin.emojis.favorite') }}" class="inline-form favorite-form">
                                                        @csrf
                                                        <input type="hidden" name="custom_emoji_id" value="{{ $emoji->custom_emoji_id }}">
                                                        <button type="submit" class="favorite-btn {{ $emoji->is_favorite ? 'on' : '' }}"
                                                                title="{{ $emoji->is_favorite ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها' }}"
                                                                aria-label="{{ $emoji->is_favorite ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها' }}">
                                                            ★
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="{{ route('admin.emojis.delete') }}" class="inline-form"
                                                          onsubmit="return confirm('این ایموجی از لیست انتخاب حذف شود؟');">
                                                        @csrf
                                                        <input type="hidden" name="emoji_key" value="{{ $emoji->custom_emoji_id }}">
                                                        <button type="submit" class="btn btn-sm btn-danger" title="حذف">×</button>
                                                    </form>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach

                    {{-- IMPORT PACK MODAL --}}
                    <div class="modal" id="import-pack-modal" role="dialog" aria-modal="true" aria-labelledby="import-pack-title">
                        <div class="modal-overlay" data-modal-close></div>
                        <div class="modal-box">
                            <div class="modal-header">
                                <h4 id="import-pack-title">وارد کردن پک ایموجی پرمیوم</h4>
                                <button type="button" class="modal-close" data-modal-close aria-label="بستن">×</button>
                            </div>
                            <div class="modal-body">
                                <form method="POST" action="{{ route('admin.emojis.import-pack') }}">
                                    @csrf
                                    <div class="form-group">
                                        <label>نام پک (Set Name) <span class="required">*</span></label>
                                        <input type="text" name="set_name" class="form-input" required maxlength="190" placeholder="مثال: Connectix Premium Pack">
                                    </div>
                                    <div class="form-group">
                                        <label>Custom Emoji ID <span class="required">*</span></label>
                                        <input type="text" name="custom_emoji_id" class="form-input" required maxlength="100" placeholder="شناسه ایموجی که تلگرام می‌دهد (مثال: 54321...)">
                                    </div>
                                    <div class="form-group">
                                        <label>فال‌بک ایموجی <span class="required">*</span></label>
                                        <input type="text" name="emoji_char" class="form-input" required maxlength="8" placeholder="❓">
                                    </div>
                                    <button type="submit" class="btn btn-primary">وارد کردن</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            @else
                {{-- REGISTRY TABS --}}
                @php
                    $grouped = $grouped[$section] ?? [];
                @endphp

                <div class="emoji-registry">
                    @if (! empty($grouped))
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>کلید</th>
                                        <th>نام</th>
                                        <th>فال‌بک</th>
                                        <th>Custom Emoji ID</th>
                                        <th>توضیح</th>
                                        <th>عملیات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($grouped as $emoji)
                                        <tr>
                                            <td>{{ $emoji->emoji_key }}</td>
                                            <td>{{ $emoji->name }}</td>
                                            <td>{{ $emoji->emoji_char }}</td>
                                            <td>{{ $emoji->custom_emoji_id ?: '—' }}</td>
                                            <td>{{ $emoji->description }}</td>
                                            <td>
                                                <form method="POST" action="{{ route('admin.emojis.delete') }}" class="inline-form"
                                                      onsubmit="return confirm('این ایموجی حذف شود؟');">
                                                    @csrf
                                                    <input type="hidden" name="emoji_key" value="{{ $emoji->emoji_key }}">
                                                    <button type="submit" class="btn btn-sm btn-danger">حذف</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">این بخش خالی است. از فرم زیر یک ایموجی جدید تعریف کنید.</p>
                    @endif
                </div>

                {{-- ADD/EDIT EMOTION FORM --}}
                <div class="card-sep"></div>
                <div class="emoji-form">
                    <h3>تعریف / ویرایش ایموجی</h3>
                    <form method="POST" action="{{ route('admin.emojis.update') }}">
                        @csrf
                        <div class="form-row">
                            <div class="form-group col-3">
                                <label>کلید <span class="required">*</span></label>
                                <input type="text" name="emoji_key" class="form-input" required maxlength="64"
                                       placeholder="مثال: msg_success, btn_buy, plan_gold">
                            </div>
                            <div class="form-group col-3">
                                <label>نام <span class="required">*</span></label>
                                <input type="text" name="name" class="form-input" required maxlength="190"
                                       placeholder="مثال: پیام موفقیت، دکمه خرید">
                            </div>
                            <div class="form-group col-3">
                                <label>بخش <span class="required">*</span></label>
                                <select name="section" class="form-select" required>
                                    @foreach ($sections as $key => $label)
                                        <option value="{{ $key }}" {{ $section === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-3">
                                <label>فال‌بک ایموجی <span class="required">*</span></label>
                                <input type="text" name="emoji_char" class="form-input" required maxlength="8"
                                       placeholder="😀">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-6">
                                <label>Custom Emoji ID (پرمیوم)</label>
                                <input type="text" name="custom_emoji_id" class="form-input" maxlength="100"
                                       placeholder="شناسه ایموجی پرمیوم">
                            </div>
                            <div class="form-group col-6">
                                <label>توضیح</label>
                                <input type="text" name="description" class="form-input" maxlength="255"
                                       placeholder="توضیح کوتاه برای ادمین‌ها">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">ذخیره ایموجی</button>
                    </form>
                </div>
            @endif
        </div>
    </div>

</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Modal open/close
    document.querySelectorAll('[data-modal-open]').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.modalOpen;
            document.getElementById(id)?.classList.add('open');
        });
    });
    document.querySelectorAll('[data-modal-close]').forEach(el => {
        el.addEventListener('click', () => {
            el.closest('.modal')?.classList.remove('open');
        });
    });
    // Favourite star AJAX-ify (optional, keeps page without reload)
    document.querySelectorAll('.favorite-form').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('button');
            const fd = new FormData(form);
            const res = await fetch('{{ route('admin.emojis.favorite') }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                body: fd,
            });
            if (res.ok) {
                btn.classList.toggle('on');
                btn.title = btn.classList.contains('on') ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها';
            }
        });
    });
});
</script>
@endpush
@endsection