@extends('layouts.admin', ['title' => 'مدیریت راهنماها', 'active' => 'guides'])

@section('content')
    <div class="card">
        <h2>راهنماهای استاندارد</h2>
        <p class="muted">برای هر پلتفرم یا ویدیوی MP4 (حداکثر ۱۰ مگابایت) بارگذاری کنید یا یک لینک بدهید.</p>

        <form method="post" action="{{ route('admin.guides.store') }}" enctype="multipart/form-data">
            @csrf

            <table>
                <thead>
                    <tr>
                        <th>پلتفرم</th>
                        <th>وضعیت فعلی</th>
                        <th>ویدیو</th>
                        <th>لینک</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($platforms as $platform)
                        @php $entry = $existing[$platform]; @endphp
                        <tr>
                            <td dir="ltr">{{ $platform }}</td>
                            <td>
                                @if ($entry['mp4'])
                                    <span class="badge ok">ویدیو ({{ number_format($entry['mp4']['size'] / 1024) }} کیلوبایت)</span>
                                @elseif ($entry['txt'])
                                    <span class="badge ok">لینک ✓</span>
                                @else
                                    <span class="badge">خالی</span>
                                @endif
                            </td>
                            <td><input type="file" name="guide_{{ $platform }}_video" accept="video/mp4"></td>
                            <td><input type="url" name="guide_{{ $platform }}_link" value="" placeholder="https://..."></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div style="margin-top:16px">
                <button type="submit">ذخیره راهنماها</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>راهنمای اختصاصی جدید</h2>
        <form method="post" action="{{ route('admin.guides.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="row">
                <div>
                    <label>عنوان</label>
                    <input type="text" name="custom_title" placeholder="مثلاً: اتصال با V2Box">
                </div>
                <div>
                    <label>ویدیو</label>
                    <input type="file" name="custom_video" accept="video/mp4">
                </div>
                <div>
                    <label>لینک</label>
                    <input type="url" name="custom_link" placeholder="https://...">
                </div>
            </div>
            <div style="margin-top:16px">
                <button type="submit">افزودن</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>راهنماهای اختصاصی موجود ({{ count($customItems) }})</h2>
        <table>
            <thead>
                <tr>
                    <th>عنوان</th>
                    <th>نوع</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($customItems as $item)
                    <tr>
                        <td>{{ $item['title'] }}</td>
                        <td>
                            @if ($item['type'] === 'video')
                                <span class="badge ok">ویدیو</span>
                            @else
                                <span class="badge">لینک</span>
                            @endif
                        </td>
                        <td>
                            <form method="post" action="{{ route('admin.guides.destroy') }}" class="inline-form"
                                  onsubmit="return confirm('حذف شود؟')">
                                @csrf
                                <input type="hidden" name="type" value="custom">
                                <input type="hidden" name="name" value="{{ $item['title'] }}">
                                <button type="submit" class="btn danger">حذف</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">راهنمای اختصاصی وجود ندارد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>حذف راهنمای استاندارد</h2>
        <form method="post" action="{{ route('admin.guides.destroy') }}" class="row" onsubmit="return confirm('حذف شود؟')">
            @csrf
            <div>
                <label>پلتفرم</label>
                <select name="name">
                    @foreach ($platforms as $platform)
                        <option value="{{ $platform }}">{{ $platform }}</option>
                    @endforeach
                </select>
                <input type="hidden" name="type" value="platform">
            </div>
            <div>
                <button type="submit" class="btn danger">حذف</button>
            </div>
        </form>
    </div>
@endsection