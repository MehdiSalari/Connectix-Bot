@extends('layouts.admin')

@section('content')
<div class="admin-page">

    <div class="page-header">
        <h1>{{ $title }}</h1>
        <p class="page-desc">کاربران مستقل (بدون اشتراک Connectix) و سرویس‌های محلی‌شان. سرویس از روی پلن ساخته می‌شود (موجودی کیف پول در صورت درخواست کسر می‌شود). تامین اکانت روی پنل VPN در ویو ۴ پیاده‌سازی می‌شود.</p>
    </div>

    {{-- FILTERS / SEARCH --}}
    <div class="card">
        <div class="card-header">
            <h3>فیلتر و جستجو</h3>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.localservices.index') }}" class="form-inline">
                <div class="form-group">
                    <label>جستجو (چت‌آیدی / نام / یوزرنیم تلگرام)</label>
                    <input type="text" name="q" class="form-input" value="{{ $filters['q'] }}" placeholder="جستجو..." style="width:280px">
                </div>
                <div class="form-group">
                    <label>پنل</label>
                    <select name="panel_id" class="form-select" style="width:220px">
                        <option value="0">همه پنل‌ها</option>
                        @foreach ($panels as $panel)
                            <option value="{{ $panel->id }}" {{ $filters['panel_id'] == $panel->id ? 'selected' : '' }}>{{ $panel->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>کاربر</label>
                    <select name="user_id" class="form-select" style="width:220px">
                        <option value="0">همه کاربران</option>
                        @foreach ($users as $u)
                            <option value="{{ $u['id'] }}" {{ $filters['user_id'] == $u['id'] ? 'selected' : '' }}>{{ $u['name'] }} ({{ $u['chat_id'] }})</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">اعمال</button>
                <a href="{{ route('admin.localservices.index') }}" class="btn btn-secondary">پاک کردن</a>
            </form>
        </div>
    </div>

    {{-- ADD USER CARD --}}
    <div class="card mt-4">
        <div class="card-header">
            <h3>افزودن کاربر جدید</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.localservices.users.store') }}">
                @csrf
                <div class="form-row">
                    <div class="form-group col-4">
                        <label>Chat ID تلگرام <span class="required">*</span></label>
                        <input type="text" name="chat_id" class="form-input" required maxlength="20"
                               placeholder="مثال: 123456789" pattern="[0-9]+">
                    </div>
                    <div class="form-group col-4">
                        <label>نام <span class="required">*</span></label>
                        <input type="text" name="name" class="form-input" required maxlength="100"
                               placeholder="مثال: علی رضایی">
                    </div>
                    <div class="form-group col-4">
                        <label>یوزرنیم تلگرام</label>
                        <input type="text" name="username" class="form-input" maxlength="64"
                               placeholder="بدون @ مثلا: ali_rezaei">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">ساخت کاربر و کیف پول</button>
            </form>
        </div>
    </div>

    {{-- SERVICES TABLE --}}
    <div class="card mt-4">
        <div class="card-header d-flex justify-between align-center">
            <h3>سرویس‌ها ({{ $total }} ردیف)</h3>
            <div class="pagination">
                @if ($page > 1)
                    <a href="{{ route('admin.localservices.index', array_merge(request()->query(), ['page' => $page - 1])) }}" class="btn btn-sm btn-secondary">قبلی</a>
                @endif
                <span class="px-2">صفحه {{ $page }}</span>
                @if ($page * $perPage < $total)
                    <a href="{{ route('admin.localservices.index', array_merge(request()->query(), ['page' => $page + 1])) }}" class="btn btn-sm btn-secondary">بعدی</a>
                @endif
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Client ID</th>
                            <th>کاربر</th>
                            <th>پلن</th>
                            <th>پنل</th>
                            <th>قیمت</th>
                            <th>دستگاه / روز / حجم</th>
                            <th>ساخته شده</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td><code>{{ $row['id'] }}</code></td>
                                <td>
                                    <a href="{{ route('admin.users.details', ['chatId' => $row['chat_id']]) }}" target="_blank">
                                        {{ $row['user_name'] ?? '—' }} ({{ $row['chat_id'] }})
                                    </a>
                                </td>
                                <td>{{ $row['plan_title'] ?? '—' }}</td>
                                <td>{{ $row['panel_name'] ?? '—' }}</td>
                                <td>{{ number_format($row['price'] ?? 0) }}</td>
                                <td>{{ $row['count_of_devices'] }}x / {{ $row['days'] ?? '—' }}D / {{ ($row['traffic_gb'] ?? 0) > 0 ? $row['traffic_gb'].'GB' : '∞' }}</td>
                                <td>{{ \Carbon\Carbon::parse($row['created_at'])->format('Y/m/d H:i') }}</td>
                                <td>
                                    <span class="badge {{ $row['is_deleted'] ? 'badge-danger' : ($row['is_blocked'] ? 'badge-warning' : 'badge-success') }}">
                                        {{ $row['is_deleted'] ? 'حذف شده' : ($row['is_blocked'] ? 'مسدود' : 'فعال') }}
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group-sm">
                                        <button type="button"
                                                class="btn btn-sm btn-primary"
                                                data-modal-open="renew-{{ $row['id'] }}"
                                                {{ $row['is_deleted'] ? 'disabled' : '' }}>
                                            تمدید
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-secondary"
                                                data-modal-open="enable-{{ $row['id'] }}"
                                                {{ $row['is_deleted'] ? 'disabled' : '' }}>
                                            {{ $row['is_enabled'] ? 'غیرفعال' : 'فعال' }}
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-warning"
                                                data-modal-open="reset-{{ $row['id'] }}"
                                                {{ $row['is_deleted'] ? 'disabled' : '' }}>
                                            ریست ترافیک
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-danger"
                                                data-modal-open="delete-{{ $row['id'] }}"
                                                {{ $row['is_deleted'] ? 'disabled' : '' }}>
                                            حذف
                                        </button>
                                    </div>

                                    {{-- RENEW MODAL --}}
                                    <div class="modal" id="renew-{{ $row['id'] }}" role="dialog" aria-modal="true" aria-labelledby="renew-{{ $row['id'] }}-title">
                                        <div class="modal-overlay" data-modal-close></div>
                                        <div class="modal-box">
                                            <div class="modal-header">
                                                <h4 id="renew-{{ $row['id'] }}-title">تمدید سرویس {{ $row['id'] }}</h4>
                                                <button type="button" class="modal-close" data-modal-close aria-label="بستن">×</button>
                                            </div>
                                            <div class="modal-body">
                                                <form method="POST" action="{{ route('admin.localservices.services.renew') }}">
                                                    @csrf
                                                    <input type="hidden" name="client_id" value="{{ $row['id'] }}">
                                                    <div class="form-group">
                                                        <label>پلن <span class="required">*</span></label>
                                                        <select name="plan_id" class="form-select" required>
                                                            @foreach ($plans as $plan)
                                                                <option value="{{ $plan['id'] }}">{{ $plan['title'] }} — {{ number_format($plan['price']) }} تومان</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="form-group">
                                                        <label><input type="checkbox" name="charge_wallet" value="1"> کسر از کیف پول کاربر</label>
                                                    </div>
                                                    <button type="submit" class="btn btn-primary">تمدید</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- ENABLE/DISABLE MODAL --}}
                                    <div class="modal" id="enable-{{ $row['id'] }}" role="dialog" aria-modal="true" aria-labelledby="enable-{{ $row['id'] }}-title">
                                        <div class="modal-overlay" data-modal-close></div>
                                        <div class="modal-box">
                                            <div class="modal-header">
                                                <h4 id="enable-{{ $row['id'] }}-title">{{ $row['is_enabled'] ? 'غیرفعال کردن' : 'فعال کردن' }} سرویس</h4>
                                                <button type="button" class="modal-close" data-modal-close aria-label="بستن">×</button>
                                            </div>
                                            <div class="modal-body">
                                                <p>{{ $row['is_enabled'] ? 'آیا سرویس غیرفعال شود؟' : 'آیا سرویس فعال شود؟' }}</p>
                                                <form method="POST" action="{{ route('admin.localservices.services.enabled') }}">
                                                    @csrf
                                                    <input type="hidden" name="client_id" value="{{ $row['id'] }}">
                                                    <input type="hidden" name="enabled" value="{{ $row['is_enabled'] ? '0' : '1' }}">
                                                    <button type="submit" class="btn btn-primary">{{ $row['is_enabled'] ? 'غیرفعال' : 'فعال' }}</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- RESET TRAFFIC MODAL --}}
                                    <div class="modal" id="reset-{{ $row['id'] }}" role="dialog" aria-modal="true" aria-labelledby="reset-{{ $row['id'] }}-title">
                                        <div class="modal-overlay" data-modal-close></div>
                                        <div class="modal-box">
                                            <div class="modal-header">
                                                <h4 id="reset-{{ $row['id'] }}-title">ریست ترافیک</h4>
                                                <button type="button" class="modal-close" data-modal-close aria-label="بستن">×</button>
                                            </div>
                                            <div class="modal-body">
                                                <p>مصرف ترافیک این سرویس به صفر برمی‌گردد. ادامه دهید؟</p>
                                                <form method="POST" action="{{ route('admin.localservices.services.reset-traffic') }}">
                                                    @csrf
                                                    <input type="hidden" name="client_id" value="{{ $row['id'] }}">
                                                    <button type="submit" class="btn btn-warning">ریست ترافیک</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- DELETE MODAL --}}
                                    <div class="modal" id="delete-{{ $row['id'] }}" role="dialog" aria-modal="true" aria-labelledby="delete-{{ $row['id'] }}-title">
                                        <div class="modal-overlay" data-modal-close></div>
                                        <div class="modal-box">
                                            <div class="modal-header">
                                                <h4 id="delete-{{ $row['id'] }}-title">حذف نرم سرویس</h4>
                                                <button type="button" class="modal-close" data-modal-close aria-label="بستن">×</button>
                                            </div>
                                            <div class="modal-body">
                                                <p class="text-danger">این کار فقط ردیف محلی را با is_deleted=1 علامت می‌زند. اکانت روی پنل VPN دست‌نخورده می‌ماند.</p>
                                                <form method="POST" action="{{ route('admin.localservices.services.destroy') }}">
                                                    @csrf
                                                    <input type="hidden" name="client_id" value="{{ $row['id'] }}">
                                                    <button type="submit" class="btn btn-danger">بله، حذف شود</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted">سرویسی یافت نشد.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- CREATE SERVICE FORM --}}
    <div class="card mt-4">
        <div class="card-header">
            <h3>ساخت سرویس جدید برای کاربر</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.localservices.services.store') }}">
                @csrf
                <div class="form-row">
                    <div class="form-group col-4">
                        <label>Chat ID کاربر <span class="required">*</span></label>
                        <input type="text" name="chat_id" class="form-input" required maxlength="20"
                               placeholder="مثال: 123456789" pattern="[0-9]+" id="create-chat-id"
                               autocomplete="off">
                        <div class="user-suggestions" id="user-suggestions" style="display:none; position:absolute; z-index:10;"></div>
                    </div>
                    <div class="form-group col-4">
                        <label>پلن <span class="required">*</span></label>
                        <select name="plan_id" class="form-select" required id="create-plan-select">
                            <option value="">انتخاب پلن</option>
                            @foreach ($plans as $plan)
                                <option value="{{ $plan['id'] }}">{{ $plan['title'] }} — {{ number_format($plan['price']) }} تومان ({{ $plan['is_free'] ? 'رایگان' : 'پرداختی' }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-4">
                        <label>
                            <input type="checkbox" name="charge_wallet" value="1" id="create-charge-wallet">
                            کسر از کیف پول
                        </label>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-12">
                        <label>یادداشت</label>
                        <input type="text" name="note" class="form-input" maxlength="255" placeholder="یادداشت ادمین (اختیاری)">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">ساخت سرویس</button>
            </form>
        </div>
    </div>

    {{-- ATTACH EXISTING ACCOUNT --}}
    <div class="card mt-4">
        <div class="card-header">
            <h3>پیوستن اکانت موجود روی پنل</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.localservices.services.attach') }}">
                @csrf
                <div class="form-row">
                    <div class="form-group col-4">
                        <label>پنل <span class="required">*</span></label>
                        <select name="panel_id" class="form-select" required>
                            <option value="">انتخاب پنل</option>
                            @foreach ($panels as $panel)
                                @if ($panel->is_active)
                                    <option value="{{ $panel->id }}">{{ $panel->name }} ({{ $types[$panel->type] ?? $panel->type }})</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-4">
                        <label>Remote Key (شناسه اکانت روی پنل) <span class="required">*</span></label>
                        <input type="text" name="remote_key" class="form-input" required maxlength="80"
                               placeholder="مثال: user_12345 یا UUID">
                    </div>
                    <div class="form-group col-4">
                        <label>Chat ID کاربر <span class="required">*</span></label>
                        <input type="text" name="chat_id" class="form-input" required maxlength="20"
                               placeholder="مثال: 123456789" pattern="[0-9]+" id="attach-chat-id">
                        <div class="user-suggestions" id="attach-user-suggestions" style="display:none; position:absolute; z-index:10;"></div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">پیوستن</button>
            </form>
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

    // User search autocomplete for create/attach forms
    function setupUserSearch(inputId, suggestionsId) {
        const input = document.getElementById(inputId);
        const box = document.getElementById(suggestionsId);
        if (!input || !box) return;

        let debounce = null;
        input.addEventListener('input', () => {
            clearTimeout(debounce);
            const q = input.value.trim();
            if (q.length < 2) {
                box.style.display = 'none';
                return;
            }
            debounce = setTimeout(async () => {
                const res = await fetch('{{ route('admin.localservices.users.search') }}?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    if (data.ok && data.users?.length) {
                        box.innerHTML = data.users.map(u => `
                            <div class="suggestion-item" data-chat="${u.chat_id}" data-name="${u.name}">
                                ${u.name} (${u.chat_id})${u.username ? ' @' + u.username : ''}
                            </div>
                        `).join('');
                        box.style.display = 'block';
                    } else {
                        box.style.display = 'none';
                    }
                }
            }, 200);
        });

        box.addEventListener('click', e => {
            const item = e.target.closest('.suggestion-item');
            if (item) {
                input.value = item.dataset.chat;
                box.style.display = 'none';
            }
        });

        document.addEventListener('click', e => {
            if (!box.contains(e.target) && e.target !== input) {
                box.style.display = 'none';
            }
        });
    }

    setupUserSearch('create-chat-id', 'user-suggestions');
    setupUserSearch('attach-chat-id', 'attach-user-suggestions');
});
</script>
@endpush
@endsection