@php
    /**
     * The installation report, in the shape the specification asks for:
     *
     *   Installation Check
     *   [x] Database
     *   [x] Database Schema
     *   ...
     *
     * A critical failure is what stops the wizard from completing, so it is
     * shown in red and everything else in green or amber. No value is ever
     * rendered here except a check's own message, and those never contain a
     * credential.
     */
@endphp
<table>
    <tr>
        <th style="width: 70%">بررسی</th>
        <th>وضعیت</th>
        <th>جزئیات</th>
    </tr>
    @foreach ($checks as $check)
        <tr>
            <td>
                <span class="{{ $check['ok'] ? 'row-ok' : ($check['critical'] ? 'row-no' : 'row-warn') }}"></span>
                {{ $check['label'] }}
                @unless ($check['critical'])
                    <span class="muted">(غیر بحرانی)</span>
                @endunless
            </td>
            <td>{{ $check['ok'] ? 'موفق' : 'ناموفق' }}</td>
            <td class="muted">{{ $check['message'] }}</td>
        </tr>
    @endforeach
</table>
