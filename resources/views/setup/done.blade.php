@extends('setup.layout')

@section('title', 'نصب کامل شد')

@section('content')
    <div class="alert ok">
        نصب با موفقیت انجام شد. از این پس برنامه وارد جریان عادی می‌شود و مسیر <code>/setup</code>
        فقط برای مدیر واردشده باز است.
    </div>

    <table>
        <tr>
            <th style="width: 40%">مورد</th>
            <th>مقدار</th>
        </tr>
        <tr>
            <td>وضعیت</td>
            <td>{{ $progress['state']->label() }}</td>
        </tr>
        <tr>
            <td>نام برنامه</td>
            <td>{{ $summary['app_name'] }}</td>
        </tr>
        <tr>
            <td>دیتابیس</td>
            <td><code>{{ $summary['database'] }}</code></td>
        </tr>
        <tr>
            <td>مدیران</td>
            <td>{{ number_format($summary['admins']) }}</td>
        </tr>
        <tr>
            <td>کاربران</td>
            <td>{{ number_format($summary['users']) }}</td>
        </tr>
        <tr>
            <td>مشتریان</td>
            <td>{{ number_format($summary['clients']) }}</td>
        </tr>
        <tr>
            <td>پایان نصب</td>
            <td>{{ $summary['completed_at'] ?? '—' }}</td>
        </tr>
    </table>

    @include('setup.partials.checks')

    <div class="actions">
        <a href="{{ route('admin.login') }}"><button type="button">ورود به پنل مدیریت</button></a>
    </div>

    <h3>قدم بعدی</h3>
    <ul class="plain">
        <li>اگر هنوز از نصب قبلی داده‌ای دارید، از مرحله «انتقال اطلاعات» استفاده کنید.</li>
        <li>زمان‌بندی روزانه را روی هاست فعال کنید: <code>* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run</code></li>
        <li>برای بررسی دوباره همین مراحل، همین صفحه در دسترس مدیر باقی می‌ماند.</li>
    </ul>
@endsection
