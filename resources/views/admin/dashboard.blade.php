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

    // شمارندههای KPI با ارقام فارسی و جداکنندهٔ هزارگان چاپ میشوند —
    // همان قاعدهای که رشتههای تاریخ و نشان تیکتها دارند.
    $faNum = static fn (int $value): string => $persian(number_format($value));

    // بازهٔ نمودارها: فقط همین سه گزینه از سرور پذیرفته میشود (کنترلر
    // هم همینها را میپذیرد؛ هر مقدار دیگری به ۷ برمیگردد).
    $range = (int) ($stats['range'] ?? 7);
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
    .dash-blocked { font-size: 12px; color: var(--danger); font-weight: 700; margin-top: 6px; display: block; }
    .dash-range { display: flex; align-items: center; gap: 8px; justify-content: flex-end; margin-top: 16px; }
    .dash-range label { color: var(--text-faint); font-size: 12.5px; font-weight: 600; }
    .dash-range select { min-width: 10rem; }
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

    {{-- ردیف KPI به ترتیب و برچسب فورک: کل کاربران (با شمار مسدودها)،
         مشتریانِ دستکم یک خرید موفق، سه کارت درآمد، تیکتهای باز و
         نمایندگان فعال. دلتا و واحدِ کارتهای قبلی سر جای خود مانده است. --}}
    <div class="dash-kpis">
        <div class="stat">
            <div class="k">کل کاربران</div>
            <div class="v">{{ $faNum($stats['users_total']) }}</div>
            <span class="dash-delta">{!! $deltaHtml($stats['new_users_delta'], 'نسبت به هفتهٔ قبل') !!}</span>
            <span class="dash-unit">{{ $persian($stats['new_users_today']) }} کاربر جدید امروز</span>
            @if ($stats['blocked_users'] > 0)
                <span class="dash-blocked">{{ $faNum($stats['blocked_users']) }} مسدود</span>
            @endif
        </div>
        <div class="stat">
            <div class="k">مشتریان (حداقل ۱ خرید موفق)</div>
            <div class="v">{{ $faNum($stats['buyers_total']) }}</div>
            <span class="dash-unit">از {{ $faNum($stats['users_total']) }} کاربر</span>
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
            <div class="k">درآمد کل (تومان)</div>
            <div class="v">{{ number_format($stats['revenue_total']) }}</div>
            <span class="dash-unit">همهٔ سفارشهای ثبتشده</span>
        </div>
        <div class="stat">
            <div class="k">تیکتهای باز</div>
            <div class="v">{{ $faNum($stats['open_tickets']) }}</div>
            <span class="dash-unit">در انتظار پاسخ پشتیبانی</span>
        </div>
        <div class="stat">
            <div class="k">نمایندگان فعال</div>
            <div class="v">{{ $faNum($stats['active_resellers']) }}</div>
            <span class="dash-unit">موجودی کیف پول کل: {{ $faNum($stats['wallet_balance_total']) }}</span>
        </div>
        <div class="stat">
            <div class="k">سفارشهای در انتظار بررسی</div>
            <div class="v">{{ $faNum($stats['pending']) }}</div>
            <span class="dash-unit">سفارش‌های امروز: {{ $persian($stats['orders_today']) }}</span>
        </div>
    </div>

    {{-- بازهٔ نمودارها: همان select فورک (۷/۳۰/۹۰ روز) که با انتخاب، فرم را
         میفرستد؛ سرور فقط همین سه مقدار را میپذیرد و سریها را دوباره میخواند. --}}
    <div class="dash-range">
        <form method="get" action="{{ route('admin.dashboard') }}">
            <label for="dash-range">بازهٔ نمودارها</label>
            <select name="range" id="dash-range" onchange="this.form.submit()">
                <option value="7" @selected($range === 7)>۷ روز اخیر</option>
                <option value="30" @selected($range === 30)>۳۰ روز اخیر</option>
                <option value="90" @selected($range === 90)>۹۰ روز اخیر</option>
            </select>
            <noscript><button class="btn" type="submit">اعمال</button></noscript>
        </form>
    </div>

    <div class="dash-charts">
        <div class="card">
            <h2>درآمد {{ $persian($range) }} روز اخیر <span class="dash-unit">(تومان)</span></h2>
            <div class="dash-chart-box">
                <canvas id="dashRevenueChart"
                        data-chart="revenue"
                        data-labels="{{ json_encode(array_column($stats['series_revenue'], 'label'), JSON_UNESCAPED_UNICODE) }}"
                        data-values="{{ json_encode(array_column($stats['series_revenue'], 'value')) }}"></canvas>
            </div>
        </div>
        <div class="card">
            <h2>کاربران جدید {{ $persian($range) }} روز اخیر</h2>
            <div class="dash-chart-box">
                <canvas id="dashUsersChart"
                        data-chart="users"
                        data-labels="{{ json_encode(array_column($stats['series_users'], 'label'), JSON_UNESCAPED_UNICODE) }}"
                        data-values="{{ json_encode(array_column($stats['series_users'], 'value')) }}"></canvas>
            </div>
        </div>
    </div>

    {{-- خمیرهٔ وضعیت تراکنشها و جدول پراستفادهترین کدهای تخفیف: همان
         چیدمان دو ستونی فورک، زیر نمودارهای روند. --}}
    <div class="dash-charts">
        <div class="card">
            <h2>وضعیت تراکنشها</h2>
            <div class="dash-chart-box">
                <canvas id="dashStatusChart"
                        data-chart="status"
                        data-labels="{{ json_encode(['پرداخت شده', 'رد شده', 'در انتظار'], JSON_UNESCAPED_UNICODE) }}"
                        data-values="{{ json_encode([$stats['status_counts']['success'], $stats['status_counts']['rejected'], $stats['status_counts']['pending']]) }}"></canvas>
            </div>
        </div>
        <div class="card">
            <h2>پراستفادهترین کدهای تخفیف</h2>
            <table>
                <thead>
                    <tr>
                        <th>کد</th>
                        <th>تعداد استفاده</th>
                        <th>درآمد (تومان)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stats['top_coupons'] as $coupon)
                        <tr>
                            <td class="ltr">{{ $coupon['coupon'] }}</td>
                            <td>{{ $faNum($coupon['uses']) }}</td>
                            <td>{{ number_format($coupon['revenue']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="muted">در بازهٔ انتخابی هیچ کد تخفیفی استفاده نشده است.</td></tr>
                    @endforelse
                </tbody>
            </table>
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
                var ok = (cs.getPropertyValue('--ok') || '').trim() || '#34d399';
                var warn = (cs.getPropertyValue('--warn') || '').trim() || '#fbbf24';
                var danger = (cs.getPropertyValue('--danger') || '').trim() || '#fb7185';

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

                // خمیرهٔ وضعیت تراکنشها: سه برش با رنگهای سیستم (تاییدشده،
                // رد شده، در انتظار) — برچسبها و مقادیر از همان data-* میآیند.
                function doughnut(id) {
                    var el = document.getElementById(id);
                    if (!el) return;

                    var labels = JSON.parse(el.dataset.labels || '[]');
                    var values = JSON.parse(el.dataset.values || '[]');

                    new Chart(el, {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: values,
                                backgroundColor: [ok, danger, warn],
                                borderColor: 'transparent',
                                hoverOffset: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '62%',
                            plugins: {
                                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14 } }
                            }
                        }
                    });
                }

                build('dashRevenueChart', 'bar', false);
                build('dashUsersChart', 'line', true);
                doughnut('dashStatusChart');
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', boot);
            } else {
                boot();
            }
        })();
    </script>
@endsection
