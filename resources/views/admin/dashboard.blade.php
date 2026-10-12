@extends('layouts.admin', ['title' => 'داشبورد', 'active' => 'dashboard'])

@section('content')
@php
    // دلتاها: وقتی دورهٔ قبل صفر است درصد بی‌معناست («+۱۰۰٪» دروغ می‌گوید)
    // و به‌جایش «—» می‌نشیند. قاعدهٔ v24 داشبورد قدیمی.
    $deltaHtml = function (?float $pct, string $vs): string {
        if ($pct === null) {
            return '<span class="cx-delta-muted">— <span class="cx-delta-vs">'.$vs.'</span></span>';
        }

        $up = $pct >= 0;
        $sign = $up ? '' : '-';

        return '<span class="cx-delta '.($up ? 'up' : 'down').'">'.($up ? '↑' : '↓').' '.number_format(abs($pct), 1).'%</span>'
            .' <span class="cx-delta-vs">'.$vs.'</span>';
    };

    $persian = static fn (string|int $value): string => \App\Support\PersianCalendar::toPersianDigits((string) $value);
@endphp
<style>
    /* فقط همین صفحه: به connectix.css دست نمی‌زنیم، از همان متغیرها و
       کلاس‌های سیستم طراحی (.card .stat .grid .badge) بهره می‌بریم. */
    .dash-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; }
    .dash-charts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-top: 16px; }
    .dash-chart-box { position: relative; height: 240px; margin-top: 14px; }
    .dash-delta { font-size: 12.5px; margin-top: 8px; display: block; }
    .cx-delta { font-weight: 800; }
    .cx-delta.up { color: var(--ok); }
    .cx-delta.down { color: var(--danger); }
    .cx-delta-muted { color: var(--text-faint); }
    .cx-delta-vs { color: var(--text-faint); font-weight: 500; }
    .dash-unit { font-size: 12px; color: var(--text-faint); font-weight: 500; }
    @media (max-width: 900px) { .dash-charts { grid-template-columns: 1fr; } }
</style>

    {{-- عکس برنامه: مثل پنل قدیمی، از صفحه‌ی عمومی t.me خودِ ربات گرفته می‌شود
         (TelegramProfileService::botProfile آن را کش می‌کند). --}}
    <div class="card">
        <div class="cell-user lg">
            <span class="avatar lg">{{ mb_substr(trim((string) $appName) !== '' ? (string) $appName : 'R', 0, 1) }}@if ($bot['avatar'] ?? null)<img src="{{ $bot['avatar'] }}" alt="" loading="lazy" onerror="this.remove()">@endif</span>
            <div>
                <h2>پنل مدیریت {{ $appName }}</h2>
                <p class="muted">@if (! empty($bot['username']))<span dir="ltr">&#64;{{ $bot['username'] }}</span> · @endifخوش آمدید{{ isset($admin?->email) ? '، '.$admin->email : '' }}</p>
            </div>
        </div>
    </div>

    <div class="dash-kpis">
        <div class="stat">
            <div class="k">کاربران</div>
            <div class="v">{{ number_format($stats['users_total']) }}</div>
            <span class="dash-delta">{!! $deltaHtml($stats['new_users_delta'], 'نسبت به هفتهٔ قبل') !!}</span>
            <span class="dash-unit">{{ $persian($stats['new_users_today']) }} کاربر جدید امروز</span>
        </div>
        <div class="stat">
            <div class="k">درآمد امروز (تومان)</div>
            <div class="v">{{ number_format($stats['revenue_today']) }}</div>
            <span class="dash-delta">{!! $deltaHtml($stats['revenue_today_delta'], 'نسبت به دیروز') !!}</span>
        </div>
        <div class="stat">
            <div class="k">درآمد این ماه (تومان)</div>
            <div class="v">{{ number_format($stats['revenue_month']) }}</div>
            <span class="dash-delta">{!! $deltaHtml($stats['revenue_month_delta'], 'نسبت به همین بازهٔ ماه قبل') !!}</span>
        </div>
        <div class="stat">
            <div class="k">سفارش‌های در انتظار بررسی</div>
            <div class="v">{{ number_format($stats['pending']) }}</div>
            <span class="dash-unit">سفارش‌های امروز: {{ $persian($stats['orders_today']) }}</span>
        </div>
    </div>

    <div class="dash-charts">
        <div class="card">
            <h2>درآمد ۷ روز اخیر <span class="dash-unit">(تومان)</span></h2>
            <div class="dash-chart-box">
                <canvas id="dashRevenueChart"
                        data-chart="revenue"
                        data-labels="{{ json_encode(array_column($stats['series_7d_revenue'], 'label'), JSON_UNESCAPED_UNICODE) }}"
                        data-values="{{ json_encode(array_column($stats['series_7d_revenue'], 'value')) }}"></canvas>
            </div>
        </div>
        <div class="card">
            <h2>کاربران جدید ۷ روز اخیر</h2>
            <div class="dash-chart-box">
                <canvas id="dashUsersChart"
                        data-chart="users"
                        data-labels="{{ json_encode(array_column($stats['series_7d_users'], 'label'), JSON_UNESCAPED_UNICODE) }}"
                        data-values="{{ json_encode(array_column($stats['series_7d_users'], 'value')) }}"></canvas>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>آخرین کاربران</h2>
        <table>
            <thead>
                <tr>
                    <th>شناسه گفتگو</th>
                    <th>نام</th>
                    <th>تلگرام</th>
                    <th>تاریخ عضویت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recent_users as $user)
                    <tr>
                        <td class="ltr">{{ $user->chat_id }}</td>
                        <td>{{ $user->name ?? '-' }}</td>
                        <td class="ltr">{{ $user->telegram_id ?? '-' }}</td>
                        <td>{{ $user->created_at?->format('Y-m-d H:i') }}</td>
                        <td><a class="btn ghost" href="{{ route('admin.users.show', $user) }}">مشاهده</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">کاربری ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>آخرین سفارش‌ها</h2>
        <table>
            <thead>
                <tr>
                    <th>شماره سفارش</th>
                    <th>کاربر</th>
                    <th>مبلغ (تومان)</th>
                    <th>روش پرداخت</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stats['recent_orders'] as $order)
                    <tr>
                        <td class="ltr">{{ $order['order_number'] !== '' ? $order['order_number'] : '-' }}</td>
                        <td>@if ($order['user_name'] !== '') {{ $order['user_name'] }} @else <span class="muted">-</span> @endif</td>
                        <td>{{ number_format($order['amount']) }}</td>
                        <td>{{ $order['method_label'] }}</td>
                        <td><span class="badge {{ $order['status_tone'] }}">{{ $order['status_label'] }}</span></td>
                        <td>{{ $order['created_at'] ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">سفارشی ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Chart.js لوکال است (public/vendor/chart.umd.min.js): CSP فقط 'self'
         اجازه می‌دهد و هیچ CDNی در کار نیست. defer تا رندر صفحه بلاک نشود؛
         گاردِ typeof Chart صفحه را در نبودِ فایل (شبکهٔ تست) سالم نگه می‌دارد. --}}
    <script src="{{ asset('vendor/chart.umd.min.js') }}" defer></script>
    <script>
        (function () {
            var boot = function () {
                if (typeof Chart === 'undefined') return; // Chart.js نیامد؛ صفحه بدون نمودار هم سالم است

                var cs = getComputedStyle(document.documentElement);
                var accent = (cs.getPropertyValue('--accent') || '').trim() || '#8b5cf6';
                var border = (cs.getPropertyValue('--border') || '').trim() || '#2a2d48';
                var muted = (cs.getPropertyValue('--text-faint') || '').trim() || '#888';

                function rgba(hex, a) {
                    hex = hex.replace('#', '');
                    if (hex.length === 3) hex = hex.split('').map(function (c) { return c + c; }).join('');
                    var n = parseInt(hex, 16);
                    if (isNaN(n)) return 'rgba(139, 92, 246, ' + a + ')';
                    return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
                }

                Chart.defaults.font.family = "Vazirmatn, Tahoma, sans-serif";
                Chart.defaults.color = muted;

                function build(id, type, fill) {
                    var el = document.getElementById(id);
                    if (!el) return;

                    var labels = JSON.parse(el.dataset.labels || '[]');
                    var values = JSON.parse(el.dataset.values || '[]');

                    new Chart(el, {
                        type: type,
                        data: {
                            labels: labels,
                            datasets: [{
                                label: '',
                                data: values,
                                borderColor: accent,
                                backgroundColor: fill ? rgba(accent, .18) : rgba(accent, .75),
                                borderWidth: 2.5,
                                borderRadius: 8,
                                tension: .4,
                                pointRadius: 2,
                                pointHoverRadius: 5,
                                fill: !!fill
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true } },
                                y: {
                                    beginAtZero: true,
                                    grid: { color: rgba(border.indexOf('#') === 0 ? border : '#888888', .6) },
                                    border: { display: false },
                                    ticks: { precision: 0 }
                                }
                            }
                        }
                    });
                }

                build('dashRevenueChart', 'bar', false);
                build('dashUsersChart', 'line', true);
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', boot);
            } else {
                boot();
            }
        })();
    </script>
@endsection
