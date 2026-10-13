@extends('layouts.admin')

@section('content')
<div class="admin-page">

    <div class="page-header">
        <h1>{{ $title }}</h1>
        <p class="page-desc">مدیریت دکمه‌های کیبورد ربات در سه تب: منوی اصلی، خرید/تمدید، دانلود نرم‌افزار.</p>
    </div>

    @if ($savedKey)
        <div class="flash success" style="margin-bottom:1rem">دکمه «{{ $savedKey }}» ذخیره شد.</div>
    @endif

    <div class="card">
        <div class="card-header">
            <ul class="tabs" role="tablist">
                @foreach ($screens as $key => $label)
                    <li role="presentation">
                        <a href="{{ route('admin.buttons.index', ['screen' => $key]) }}"
                           role="tab"
                           aria-selected="{{ $screen === $key }}"
                           class="{{ $screen === $key ? 'active' : '' }}">
                            {{ $label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="card-body p-0">
            @if ($screen === 'main_menu')
                {{-- THE BOARD — a 3×N grid where the admin drags rows around.
                     The fork renders <table class="board"> with data-row/col and
                     the JS handles drag reorder. We mirror that markup exactly. --}}
                <div class="board-wrap">
                    <table class="board" data-screen="main_menu">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">ستون ۱</th>
                                <th scope="col">ستون ۲</th>
                                <th scope="col">ستون ۳</th>
                                <th scope="col">ستون ۴</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($buttons as $btn)
                                <tr data-id="{{ $btn->id }}"
                                    data-key="{{ $btn->button_key }}"
                                    data-sort="{{ $btn->sort_order }}"
                                    data-row="{{ $btn->row_num }}"
                                    data-col="{{ $btn->col_num }}">
                                    <td class="btn-cell"
                                        style="background:{{ $btn->button_color !== 'none' ? $btn->button_color : $defaultColor }}"
                                        data-id="{{ $btn->id }}"
                                        data-key="{{ $btn->button_key }}"
                                        data-row="{{ $btn->row_num }}"
                                        data-col="{{ $btn->col_num }}">
                                        @if ($btn->emoji)
                                            <span class="btn-emoji">{{ $btn->emoji }}</span>
                                        @elseif ($btn->custom_emoji_id)
                                            <span class="btn-emoji premium" title="{{ $btn->custom_emoji_id }}">★</span>
                                        @endif
                                        <span class="btn-label">{{ $btn->current_label }}</span>
                                        <span class="btn-status {{ $btn->is_active ? 'on' : 'off' }}"
                                              data-key="{{ $btn->button_key }}"
                                              title="کلیک برای تغییر وضعیت">
                                            {{ $btn->is_active ? '●' : '○' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="board-actions">
                    <button type="button" class="btn btn-primary" id="save-board">ذخیره چیدمان</button>
                    <span class="hint">دکمه‌ها را بکشید و رها کنید، سپس «ذخیره چیدمان» را بزنید.</span>
                </div>

                {{-- The default-colour card (the fork called it "رنگ پیش‌فرض دکمه‌های بدون رنگ"). --}}
                <div class="card-sep"></div>
                <div class="default-color-card">
                    <h4>رنگ پیش‌فرض دکمه‌های بدون رنگ</h4>
                    <form method="POST" action="{{ route('admin.buttons.default-color') }}">
                        @csrf
                        <div class="color-picker">
                            @foreach (['blue' => 'آبی', 'green' => 'سبز', 'red' => 'قرمز', 'none' => 'پیش‌فرض تلگرام'] as $val => $label)
                                <label class="color-option {{ $defaultColor === $val ? 'active' : '' }}">
                                    <input type="radio" name="default_button_color" value="{{ $val }}"
                                           {{ $defaultColor === $val ? 'checked' : '' }}>
                                    <span class="swatch {{ $val }}"></span>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <button type="submit" class="btn btn-primary">ذخیره</button>
                    </form>
                </div>

            @else
                {{-- BUY SCREEN / OS SCREEN — simple flat lists with inline edit. --}}
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>کلید</th>
                                <th>برچسب</th>
                                <th>ایموجی</th>
                                <th>Custom Emoji ID</th>
                                <th>رنگ</th>
                                <th>لینک (open_url)</th>
                                <th>مخفی بعد از تست</th>
                                <th>وضعیت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($buttons as $btn)
                                <tr data-id="{{ $btn->id }}">
                                    <td>{{ $btn->button_key }}</td>
                                    <td><input type="text" name="current_label" value="{{ $btn->current_label }}" class="form-input" style="width:180px"></td>
                                    <td><input type="text" name="emoji" value="{{ $btn->emoji }}" class="form-input" placeholder="😀" maxlength="8"></td>
                                    <td><input type="text" name="custom_emoji_id" value="{{ $btn->custom_emoji_id }}" class="form-input" placeholder="54321..."></td>
                                    <td>
                                        <select name="button_color" class="form-select">
                                            @foreach (['none' => 'بدون رنگ', 'blue' => 'آبی', 'green' => 'سبز', 'red' => 'قرمز'] as $val => $label)
                                                <option value="{{ $val }}" {{ $btn->button_color === $val ? 'selected' : '' }}>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="text" name="link_url" value="{{ $btn->link_url ?? '' }}" class="form-input" placeholder="https://..."></td>
                                    <td><input type="checkbox" name="hide_after_test" {{ $btn->hide_after_test ? 'checked' : '' }}></td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.buttons.toggle') }}" class="inline-form">
                                            @csrf
                                            <input type="hidden" name="button_key" value="{{ $btn->button_key }}">
                                            <button type="submit" class="status-badge {{ $btn->is_active ? 'on' : 'off' }}"
                                                    title="{{ $btn->is_active ? 'غیرفعال کردن' : 'فعال کردن' }}">
                                                {{ $btn->is_active ? 'فعال' : 'غیرفعال' }}
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.buttons.update') }}" class="inline-form">
                                            @csrf
                                            <input type="hidden" name="button_key" value="{{ $btn->button_key }}">
                                            <input type="hidden" name="screen" value="{{ $screen }}">
                                            <button type="submit" class="btn btn-sm btn-primary" title="ذخیره این ردیف">💾</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ADD NEW BUTTON (the fork's "تعریف کلید جدید" card) — available on every tab. --}}
    <div class="card mt-4">
        <div class="card-header">
            <h3>افزودن / ویرایش دکمه</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.buttons.update') }}">
                @csrf
                <input type="hidden" name="screen" value="{{ $screen }}">

                <div class="form-row">
                    <div class="form-group col-4">
                        <label>کلید دکمه <span class="required">*</span></label>
                        <input type="text" name="button_key" class="form-input" required maxlength="64"
                               placeholder="مثال: menu_buy, app_windows, renew_flow">
                    </div>
                    <div class="form-group col-4">
                        <label>برچسب <span class="required">*</span></label>
                        <input type="text" name="current_label" class="form-input" required maxlength="190"
                               placeholder="مثال: 🛒 خرید اشتراک">
                    </div>
                    <div class="form-group col-4">
                        <label>ایموجی (fallback)</label>
                        <input type="text" name="emoji" class="form-input" maxlength="8" placeholder="😀">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-4">
                        <label>Custom Emoji ID (پرمیوم)</label>
                        <input type="text" name="custom_emoji_id" class="form-input" maxlength="100"
                               placeholder="شناسه ایموجی پرمیوم تلگرام">
                    </div>
                    <div class="form-group col-4">
                        <label>رنگ دکمه</label>
                        <select name="button_color" class="form-select">
                            <option value="none">بدون رنگ (پیش‌فرض)</option>
                            <option value="blue">آبی</option>
                            <option value="green">سبز</option>
                            <option value="red">قرمز</option>
                        </select>
                    </div>
                    <div class="form-group col-4">
                        <label>لینک (برای open_url)</label>
                        <input type="text" name="link_url" class="form-input" maxlength="2000" placeholder="https://...">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-4">
                        <label>مخفی بعد از تست اتصال</label>
                        <select name="hide_after_test" class="form-select">
                            <option value="0">خیر</option>
                            <option value="1">بله</option>
                        </select>
                    </div>
                    <div class="form-group col-4">
                        <label>وضعیت</label>
                        <select name="is_active" class="form-select">
                            <option value="1">فعال</option>
                            <option value="0">غیرفعال</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">ذخیره دکمه</button>
            </form>
        </div>
    </div>

</div>

{{-- Inline script for the drag-and-drop board (main_menu only). --}}
@push('scripts')
    @if ($screen === 'main_menu')
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const table = document.querySelector('.board');
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        let dragged = null;

        rows.forEach(tr => {
            tr.draggable = true;
            tr.addEventListener('dragstart', e => {
                dragged = tr;
                tr.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            tr.addEventListener('dragend', () => {
                tr.classList.remove('dragging');
                dragged = null;
            });
            tr.addEventListener('dragover', e => e.preventDefault());
            tr.addEventListener('drop', e => {
                e.preventDefault();
                if (dragged && dragged !== tr) {
                    const tbody = table.querySelector('tbody');
                    const all = Array.from(tbody.querySelectorAll('tr'));
                    const from = all.indexOf(dragged);
                    const to = all.indexOf(tr);
                    if (from < to) tbody.insertBefore(dragged, tr.nextSibling);
                    else tbody.insertBefore(dragged, tr);
                }
            });
        });

        document.getElementById('save-board')?.addEventListener('click', async () => {
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            const payload = rows.map((tr, i) => ({
                id: parseInt(tr.dataset.id, 10),
                sort_order: i,
                row_num: parseInt(tr.dataset.row, 10),
                col_num: parseInt(tr.dataset.col, 10),
            }));
            const res = await fetch('{{ route('admin.buttons.reorder') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ rows: payload }),
            });
            if (res.ok) {
                window.location.reload();
            } else {
                alert('ذخیره ناموفق بود.');
            }
        });
    });
    </script>
    @endif
@endpush
@endsection