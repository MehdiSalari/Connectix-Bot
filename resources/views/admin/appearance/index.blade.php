@extends('layouts.admin', ['active' => 'appearance', 'title' => 'ظاهر و تم'])

@section('content')
    @php
        // The card preview needs each theme's own palette, not the page's
        // active one, so every theme carries its swatches here — the same
        // values the data-theme blocks in connectix.css set.
        $palettes = [
            'dark' => ['bg' => '#0f1120', 'card' => '#181a2e', 'primary' => '#a78bfa', 'primary2' => '#818cf8'],
            'light' => ['bg' => '#f4f5fb', 'card' => '#ffffff', 'primary' => '#8b5cf6', 'primary2' => '#6366f1'],
            'airox-gold' => ['bg' => '#0b0d14', 'card' => '#12151f', 'primary' => '#f5b83d', 'primary2' => '#d99a1c'],
            'airox-violet' => ['bg' => '#f4f5fb', 'card' => '#ffffff', 'primary' => '#6d4aff', 'primary2' => '#5b5cf0'],
            'ocean' => ['bg' => '#eef6fb', 'card' => '#ffffff', 'primary' => '#0284c7', 'primary2' => '#06b6d4'],
            'forest' => ['bg' => '#f0f7f3', 'card' => '#ffffff', 'primary' => '#059669', 'primary2' => '#10b981'],
            'sunset' => ['bg' => '#fff6ef', 'card' => '#ffffff', 'primary' => '#ea580c', 'primary2' => '#f59e0b'],
            'rose' => ['bg' => '#fdf2f6', 'card' => '#ffffff', 'primary' => '#db2777', 'primary2' => '#f43f5e'],
            'midnight' => ['bg' => '#070d1a', 'card' => '#0f1830', 'primary' => '#22d3ee', 'primary2' => '#3b82f6'],
            'graphite' => ['bg' => '#121212', 'card' => '#1c1c1e', 'primary' => '#fbbf24', 'primary2' => '#f59e0b'],
        ];

        // Same ids as the fork: browser-local picks live in cx-theme, the
        // server-side default in panel_theme. The select posts «پیش‌فرض پنل».
        $selected = $panelTheme ?? '';
    @endphp

    <div class="card">
        <h2>تم‌های پنل</h2>
        <p class="hint">
            با کلیک روی هر کارت، همین صفحه با همان تم دیده می‌شود؛ انتخاب در مرورگرِ
            خودت ذخیره می‌شود (دکمهٔ ذخیره پایین، پیش‌فرضِ همهٔ ادمین‌ها را عوض می‌کند).
        </p>

        <div class="theme-grid" id="theme-grid">
            @foreach ($themes as $id => $label)
                @php $p = $palettes[$id] ?? ['bg' => '#111827', 'card' => '#ffffff', 'primary' => '#8b5cf6', 'primary2' => '#6366f1']; @endphp
                <button type="button"
                        class="theme-card{{ $selected === $id ? ' selected' : '' }}"
                        data-theme-id="{{ $id }}"
                        data-theme-name="{{ $label }}"
                        aria-pressed="{{ $selected === $id ? 'true' : 'false' }}">
                    <span class="theme-preview" style="background: {{ $p['bg'] }};">
                        <span class="tp-card" style="background: {{ $p['card'] }};"></span>
                        <span class="tp-accent" style="background: linear-gradient(135deg, {{ $p['primary'] }}, {{ $p['primary2'] }});"></span>
                    </span>
                    <span class="theme-name">
                        <span>{{ $label }}</span>
                        <span class="theme-check">✓</span>
                    </span>
                    <span class="theme-dots" aria-hidden="true">
                        <i style="background: {{ $p['bg'] }};"></i>
                        <i style="background: {{ $p['card'] }};"></i>
                        <i style="background: {{ $p['primary'] }};"></i>
                        <i style="background: {{ $p['primary2'] }};"></i>
                    </span>
                </button>
            @endforeach
        </div>

        <p class="hint" id="theme-live-note" hidden>
            پیش‌نمایش زنده: <b id="theme-live-name"></b> — برای برگشتن به تمِ
            انتخاب‌شده، صفحه را دوباره باز کنید.
        </p>
    </div>

    <div class="card">
        <h2>پیش‌فرض پنل</h2>
        <p class="hint">
            تمی که بازدیدکننده‌ای که هنوز تمی برای خودش انتخاب نکرده می‌بیند، و
            تم اولیهٔ وب‌اپ تلگرام.
        </p>

        <form method="post" action="{{ route('admin.appearance.store') }}" class="form-stack">
            @csrf

            <div class="field">
                <label for="panel_theme">تم پیش‌فرض پنل مدیریت</label>
                <select id="panel_theme" name="panel_theme">
                    <option value="">خودکار (تیره)</option>
                    @foreach ($themes as $id => $label)
                        <option value="{{ $id }}" @selected($panelTheme === $id)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="webapp_theme">تم پیش‌فرض وب‌اپ (داخل تلگرام)</label>
                <select id="webapp_theme" name="webapp_theme">
                    <option value="">خودکار (هماهنگ با تلگرام)</option>
                    @foreach ($themes as $id => $label)
                        <option value="{{ $id }}" @selected($webappTheme === $id)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <button type="submit">ذخیره</button>
            </div>
        </form>
    </div>

    {{-- پیش‌نمایش زنده: هر کارت تم، data-theme ریشه را عوض می‌کند و انتخاب
         را در cx-theme می‌نویسد — همان کلیدی که اسکریپت boot می‌خواند، پس
         بعد از رفرش هم همان تم دیده می‌شود. ذخیرهٔ سروری جداگانه است و
         دکمهٔ «ذخیره» آن را می‌نویسد. --}}
    @once
        <script>
            (function () {
                var root = document.documentElement;
                var cards = document.querySelectorAll('#theme-grid .theme-card');
                var note = document.getElementById('theme-live-note');
                var name = document.getElementById('theme-live-name');

                cards.forEach(function (card) {
                    card.addEventListener('click', function () {
                        var id = card.dataset.themeId;

                        root.dataset.theme = id;
                        try { localStorage.setItem('cx-theme', id); } catch (e) {}

                        cards.forEach(function (other) {
                            other.classList.toggle('selected', other === card);
                            other.setAttribute('aria-pressed', String(other === card));
                        });

                        if (note && name) {
                            name.textContent = card.dataset.themeName || id;
                            note.hidden = false;
                        }
                    });
                });
            })();
        </script>
    @endonce
@endsection
