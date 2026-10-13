@extends('layouts.admin')

@section('content')
<div class="admin-page">

    <div class="page-header">
        <h1>{{ $title }}</h1>
        <p class="page-desc">پنل‌های VPN (Marzban، 3x-ui، Remnawave، Hiddify) و پلن‌های محلی هر پنل.</p>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>پنل‌ها</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>نام</th>
                            <th>نوع</th>
                            <th>آدرس پایه</th>
                            <th>کاربر</th>
                            <th>رمز (has_secret)</th>
                            <th>SSL</th>
                            <th>فعال</th>
                            <th>تست</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($panels as $panel)
                            <tr data-id="{{ $panel['id'] }}">
                                <td>{{ $panel['id'] }}</td>
                                <td>{{ $panel['name'] }}</td>
                                <td><span class="badge badge-{{ $panel['type'] }}">{{ $types[$panel['type']] ?? $panel['type'] }}</span></td>
                                <td>{{ $panel['base_url'] }}</td>
                                <td>{{ $panel['username'] ?? '—' }}</td>
                                <td>{{ $panel['has_secret'] ? '<span class="has-secret">✓ تنظیم شده</span>' : '<span class="no-secret">خالی</span>' }}</td>
                                <td>{{ $panel['verify_ssl'] ? 'بله' : 'خیر' }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.panels.save') }}" class="inline-form">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $panel['id'] }}">
                                        <input type="hidden" name="name" value="{{ $panel['name'] }}">
                                        <input type="hidden" name="type" value="{{ $panel['type'] }}">
                                        <input type="hidden" name="base_url" value="{{ $panel['base_url'] }}">
                                        <input type="hidden" name="username" value="{{ $panel['username'] ?? '' }}">
                                        <input type="hidden" name="verify_ssl" value="{{ $panel['verify_ssl'] ? '1' : '0' }}">
                                        <input type="hidden" name="inbound_id" value="{{ $panel['inbound_id'] ?? '' }}">
                                        <input type="hidden" name="flow" value="{{ $panel['flow'] ?? '' }}">
                                        <input type="hidden" name="sub_base" value="{{ $panel['sub_base'] ?? '' }}">
                                        <input type="hidden" name="otp" value="{{ $panel['otp'] ?? '' }}">
                                        <input type="hidden" name="squads" value="{{ $panel['squads'] ?? '' }}">
                                        <input type="hidden" name="proxies_json" value="{{ $panel['proxies_json'] ?? '' }}">
                                        <input type="hidden" name="inbounds_json" value="{{ $panel['inbounds_json'] ?? '' }}">
                                        <input type="hidden" name="secret" value="">
                                        <input type="hidden" name="is_active" value="{{ $panel['is_active'] ? '0' : '1' }}">
                                        <button type="submit" class="status-badge {{ $panel['is_active'] ? 'on' : 'off' }}"
                                                title="{{ $panel['is_active'] ? 'غیرفعال' : 'فعال' }}">
                                            {{ $panel['is_active'] ? 'فعال' : 'غیرفعال' }}
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('admin.panels.test') }}" class="inline-form">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $panel['id'] }}">
                                        <button type="submit" class="btn btn-sm btn-secondary">تست اتصال</button>
                                    </form>
                                </td>
                                <td>
                                    <a href="{{ route('admin.panels.index', ['edit_panel' => $panel['id']]) }}" class="btn btn-sm btn-primary">ویرایش</a>
                                    <form method="POST" action="{{ route('admin.panels.delete') }}" class="inline-form"
                                          onsubmit="return confirm('این پنل حذف شود؟ تمام پلن‌های وابسته باید اول حذف شوند.');">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $panel['id'] }}">
                                        <button type="submit" class="btn btn-sm btn-danger">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- ADD / EDIT PANEL FORM --}}
            <div class="card-sep"></div>
            <div class="panel-form">
                <h3>{{ $editPanel ? 'ویرایش پنل' : 'افزودن پنل جدید' }}</h3>
                <form method="POST" action="{{ route('admin.panels.save') }}">
                    @csrf
                    @if ($editPanel)
                        <input type="hidden" name="id" value="{{ $editPanel['id'] }}">
                    @endif

                    <div class="form-row">
                        <div class="form-group col-4">
                            <label>نام <span class="required">*</span></label>
                            <input type="text" name="name" class="form-input" required maxlength="100"
                                   value="{{ $editPanel['name'] ?? '' }}" placeholder="مثال: Tehran Marzban">
                        </div>
                        <div class="form-group col-4">
                            <label>نوع پنل <span class="required">*</span></label>
                            <select name="type" class="form-select" required id="panel-type-select">
                                @foreach ($types as $val => $label)
                                    <option value="{{ $val }}" {{ ($editPanel['type'] ?? '') === $val ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-4">
                            <label>آدرس پایه (Base URL) <span class="required">*</span></label>
                            <input type="text" name="base_url" class="form-input" required maxlength="500"
                                   value="{{ $editPanel['base_url'] ?? '' }}" placeholder="https://panel.example.com">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-4">
                            <label>نام کاربری (برای Marzban / 3x-ui)</label>
                            <input type="text" name="username" class="form-input" maxlength="190"
                                   value="{{ $editPanel['username'] ?? '' }}" placeholder="admin">
                        </div>
                        <div class="form-group col-4">
                            <label>رمز / توکن / UUID <span class="required">*</span></label>
                            <input type="password" name="secret" class="form-input" maxlength="500"
                                   value="" placeholder="{{ $editPanel && $editPanel['has_secret'] ? 'خالی بگذارید تا تغییر نکند' : 'رمز / Bearer token / UUID ادمین' }}">
                            @if ($editPanel && $editPanel['has_secret'])
                                <small class="form-hint">خالی بگذارید تا رمز/توکن قبلی حفظ شود.</small>
                            @endif
                        </div>
                        <div class="form-group col-4">
                            <label>تأیید SSL</label>
                            <select name="verify_ssl" class="form-select">
                                <option value="1" {{ ($editPanel['verify_ssl'] ?? true) ? 'selected' : '' }}>بله</option>
                                <option value="0" {{ ! ($editPanel['verify_ssl'] ?? true) ? 'selected' : '' }}>خیر (فقط برای تست)</option>
                            </select>
                        </div>
                    </div>

                    {{-- TYPE-SPECIFIC FIELDS --}}
                    <div class="form-row" id="marzban-fields" style="{{ ($editPanel['type'] ?? '') !== 'marzban' ? 'display:none' : '' }}">
                        <div class="form-group col-6">
                            <label>Proxies JSON</label>
                            <textarea name="proxies_json" class="form-input" rows="2" placeholder='{"http": "http://user:pass@ip:port"}'>{{ $editPanel['proxies_json'] ?? '' }}</textarea>
                        </div>
                        <div class="form-group col-6">
                            <label>Inbounds JSON</label>
                            <textarea name="inbounds_json" class="form-input" rows="2" placeholder='["vless","vmess"]'>{{ $editPanel['inbounds_json'] ?? '' }}</textarea>
                        </div>
                    </div>

                    <div class="form-row" id="xui-fields" style="{{ ($editPanel['type'] ?? '') !== 'xui' ? 'display:none' : '' }}">
                        <div class="form-group col-3">
                            <label>Inbound ID <span class="required">*</span></label>
                            <input type="text" name="inbound_id" class="form-input" maxlength="20"
                                   value="{{ $editPanel['inbound_id'] ?? '' }}" placeholder="مثال: 3">
                        </div>
                        <div class="form-group col-3">
                            <label>Flow</label>
                            <input type="text" name="flow" class="form-input" maxlength="100"
                                   value="{{ $editPanel['flow'] ?? '' }}" placeholder="xtls-rprx-vision">
                        </div>
                        <div class="form-group col-3">
                            <label>Sub Base</label>
                            <input type="text" name="sub_base" class="form-input" maxlength="500"
                                   value="{{ $editPanel['sub_base'] ?? '' }}" placeholder="https://sub.example.com">
                        </div>
                        <div class="form-group col-3">
                            <label>OTP</label>
                            <input type="text" name="otp" class="form-input" maxlength="100"
                                   value="{{ $editPanel['otp'] ?? '' }}">
                        </div>
                    </div>

                    <div class="form-row" id="remnawave-fields" style="{{ ($editPanel['type'] ?? '') !== 'remnawave' ? 'display:none' : '' }}">
                        <div class="form-group col-6">
                            <label>Squads (UUIDs کاما جدا شده)</label>
                            <input type="text" name="squads" class="form-input" maxlength="1000"
                                   value="{{ $editPanel['squads'] ?? '' }}" placeholder="squad-uuid-1,squad-uuid-2">
                        </div>
                    </div>

                    <div class="form-row" id="hiddify-fields" style="{{ ($editPanel['type'] ?? '') !== 'hiddify' ? 'display:none' : '' }}">
                        <div class="form-group col-6">
                            <label>Sub Base</label>
                            <input type="text" name="sub_base" class="form-input" maxlength="500"
                                   value="{{ $editPanel['sub_base'] ?? '' }}" placeholder="https://sub.example.com">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-3">
                            <label>وضعیت</label>
                            <select name="is_active" class="form-select">
                                <option value="1" {{ ! isset($editPanel['is_active']) || $editPanel['is_active'] ? 'selected' : '' }}>فعال</option>
                                <option value="0" {{ isset($editPanel['is_active']) && ! $editPanel['is_active'] ? 'selected' : '' }}>غیرفعال</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">{{ $editPanel ? 'ذخیره تغییرات' : 'افزودن پنل' }}</button>
                    @if ($editPanel)
                        <a href="{{ route('admin.panels.index') }}" class="btn btn-secondary ms-2">انصراف</a>
                    @endif
                </form>
            </div>
        </div>
    </div>

    {{-- LOCAL PLANS --}}
    <div class="card mt-4">
        <div class="card-header">
            <h3>پلن‌های محلی</h3>
        </div>
        <div class="card-body">
            @if ($editPlan)
                <div class="plan-form">
                    <h4>ویرایش پلن</h4>
                    <form method="POST" action="{{ route('admin.panels.plan.save') }}">
                        @csrf
                        <input type="hidden" name="id" value="{{ $editPlan['id'] }}">

                        <div class="form-row">
                            <div class="form-group col-4">
                                <label>نام</label>
                                <input type="text" name="name" class="form-input" maxlength="60"
                                       value="{{ $editPlan['name'] ?? '' }}" placeholder="مثال: Gold, Silver">
                            </div>
                            <div class="form-group col-4">
                                <label>قیمت (تومان)</label>
                                <input type="number" name="price" class="form-input" min="0"
                                       value="{{ $editPlan['price'] ?? 0 }}" placeholder="0 = رایگان">
                            </div>
                            <div class="form-group col-4">
                                <label>روز <span class="required">*</span></label>
                                <input type="number" name="days" class="form-input" required min="1" max="3650"
                                       value="{{ $editPlan['days'] ?? 30 }}">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-4">
                                <label>حجم (GB) — ۰ = نامحدود</label>
                                <input type="number" step="0.1" name="traffic_gb" class="form-input" min="0" max="100000"
                                       value="{{ $editPlan['traffic_gb'] ?? 0 }}">
                            </div>
                            <div class="form-group col-4">
                                <label>تعداد دستگاه <span class="required">*</span></label>
                                <input type="number" name="devices" class="form-input" required min="1" max="20"
                                       value="{{ $editPlan['devices'] ?? 2 }}">
                            </div>
                            <div class="form-group col-4">
                                <label>گروه</label>
                                <select name="group_name" class="form-select" required>
                                    @foreach ($groups as $g)
                                        <option value="{{ $g }}" {{ ($editPlan['group_name'] ?? '') === $g ? 'selected' : '' }}>{{ $g }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-3">
                                <label>پنل <span class="required">*</span></label>
                                <select name="panel_id" class="form-select" required>
                                    @foreach ($panels as $p)
                                        @if ($p['is_active'])
                                            <option value="{{ $p['id'] }}" {{ ($editPlan['panel_id'] ?? 0) == $p['id'] ? 'selected' : '' }}>{{ $p['name'] }} ({{ $types[$p['type']] ?? $p['type'] }})</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-3">
                                <label>ترتیب</label>
                                <input type="number" name="sort_order" class="form-input" min="0"
                                       value="{{ $editPlan['sort_order'] ?? 0 }}">
                            </div>
                            <div class="form-group col-3">
                                <label>فعال</label>
                                <select name="is_active" class="form-select">
                                    <option value="1" {{ ($editPlan['is_active'] ?? true) ? 'selected' : '' }}>بله</option>
                                    <option value="0" {{ ! ($editPlan['is_active'] ?? true) ? 'selected' : '' }}>خیر</option>
                                </select>
                            </div>
                            <div class="form-group col-3">
                                <label>رایگان</label>
                                <select name="is_free" class="form-select">
                                    <option value="0" {{ ! ($editPlan['is_free'] ?? false) ? 'selected' : '' }}>خیر</option>
                                    <option value="1" {{ ($editPlan['is_free'] ?? false) ? 'selected' : '' }}>بله</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">ذخیره پلن</button>
                        <a href="{{ route('admin.panels.index') }}" class="btn btn-secondary ms-2">انصراف</a>
                    </form>
                </div>
            @else
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>پنل</th>
                                <th>عنوان</th>
                                <th>قیمت</th>
                                <th>روز / حجم / دستگاه</th>
                                <th>گروه</th>
                                <th>ترتیب</th>
                                <th>وضعیت</th>
                                <th>رایگان</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($plans as $plan)
                                <tr>
                                    <td>{{ $plan['id'] }}</td>
                                    <td>{{ $plan['panel_name'] ?? '—' }}</td>
                                    <td>{{ $planTitle($plan) }}</td>
                                    <td>{{ number_format($plan['price']) }}</td>
                                    <td>{{ $plan['days'] }}D / {{ $plan['traffic_gb'] > 0 ? $plan['traffic_gb'].'GB' : '∞' }} / {{ $plan['devices'] }}x</td>
                                    <td>{{ $plan['group_name'] }}</td>
                                    <td>{{ $plan['sort_order'] }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.panels.plan.save') }}" class="inline-form">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $plan['id'] }}">
                                            <input type="hidden" name="name" value="{{ $plan['name'] ?? '' }}">
                                            <input type="hidden" name="price" value="{{ $plan['price'] }}">
                                            <input type="hidden" name="days" value="{{ $plan['days'] }}">
                                            <input type="hidden" name="traffic_gb" value="{{ $plan['traffic_gb'] }}">
                                            <input type="hidden" name="devices" value="{{ $plan['devices'] }}">
                                            <input type="hidden" name="panel_id" value="{{ $plan['panel_id'] }}">
                                            <input type="hidden" name="group_name" value="{{ $plan['group_name'] }}">
                                            <input type="hidden" name="sort_order" value="{{ $plan['sort_order'] }}">
                                            <input type="hidden" name="is_free" value="{{ $plan['is_free'] ? '1' : '0' }}">
                                            <input type="hidden" name="is_active" value="{{ $plan['is_active'] ? '0' : '1' }}">
                                            <button type="submit" class="status-badge {{ $plan['is_active'] ? 'on' : 'off' }}">
                                                {{ $plan['is_active'] ? 'فعال' : 'غیرفعال' }}
                                            </button>
                                        </form>
                                    </td>
                                    <td>{{ $plan['is_free'] ? '✓' : '—' }}</td>
                                    <td>
                                        <a href="{{ route('admin.panels.index', ['edit_plan' => $plan['id']]) }}" class="btn btn-sm btn-primary">ویرایش</a>
                                        <form method="POST" action="{{ route('admin.panels.plan.delete') }}" class="inline-form"
                                              onsubmit="return confirm('پلن حذف شود؟ اکانت‌های قبلاً فروخته‌شده دست‌نخورده می‌مانند.');">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $plan['id'] }}">
                                            <button type="submit" class="btn btn-sm btn-danger">حذف</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="card-sep"></div>
                <div class="plan-form">
                    <h4>افزودن پلن جدید</h4>
                    <form method="POST" action="{{ route('admin.panels.plan.save') }}">
                        @csrf

                        <div class="form-row">
                            <div class="form-group col-4">
                                <label>نام</label>
                                <input type="text" name="name" class="form-input" maxlength="60" placeholder="مثال: Gold, Silver">
                            </div>
                            <div class="form-group col-4">
                                <label>قیمت (تومان)</label>
                                <input type="number" name="price" class="form-input" min="0" value="0" placeholder="0 = رایگان">
                            </div>
                            <div class="form-group col-4">
                                <label>روز <span class="required">*</span></label>
                                <input type="number" name="days" class="form-input" required min="1" max="3650" value="30">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-4">
                                <label>حجم (GB) — ۰ = نامحدود</label>
                                <input type="number" step="0.1" name="traffic_gb" class="form-input" min="0" max="100000" value="0">
                            </div>
                            <div class="form-group col-4">
                                <label>تعداد دستگاه <span class="required">*</span></label>
                                <input type="number" name="devices" class="form-input" required min="1" max="20" value="2">
                            </div>
                            <div class="form-group col-4">
                                <label>گروه <span class="required">*</span></label>
                                <select name="group_name" class="form-select" required>
                                    @foreach ($groups as $g)
                                        <option value="{{ $g }}">{{ $g }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-3">
                                <label>پنل <span class="required">*</span></label>
                                <select name="panel_id" class="form-select" required>
                                    @foreach ($panels as $p)
                                        @if ($p['is_active'])
                                            <option value="{{ $p['id'] }}">{{ $p['name'] }} ({{ $types[$p['type']] ?? $p['type'] }})</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-3">
                                <label>ترتیب</label>
                                <input type="number" name="sort_order" class="form-input" min="0" value="0">
                            </div>
                            <div class="form-group col-3">
                                <label>فعال</label>
                                <select name="is_active" class="form-select">
                                    <option value="1">بله</option>
                                    <option value="0">خیر</option>
                                </select>
                            </div>
                            <div class="form-group col-3">
                                <label>رایگان</label>
                                <select name="is_free" class="form-select">
                                    <option value="0">خیر</option>
                                    <option value="1">بله</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">افزودن پلن</button>
                    </form>
                </div>
            @endif
        </div>
    </div>

</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.getElementById('panel-type-select');
    function toggleFields() {
        const type = typeSelect?.value;
        ['marzban','xui','remnawave','hiddify'].forEach(t => {
            const el = document.getElementById(t+'-fields');
            if (el) el.style.display = t === type ? '' : 'none';
        });
    }
    typeSelect?.addEventListener('change', toggleFields);
    toggleFields();
});
</script>
@endpush
@endsection