{{--
    Published override of pagination::tailwind.

    Laravel's default tailwind markup is built out of tailwind utility classes
    (sm:flex, w-5 h-5, bg-white ...) that only exist once the tailwind css is
    compiled into the page. None of that css ships here, so the svg chevrons
    rendered at their intrinsic size and both the mobile and the desktop copy of
    the control showed at the same time. This view emits the structure the
    design system already styles (.pager -> nav[role=navigation] > ul > li)
    with Persian labels instead, so every paginated page picks it up at once.
--}}
@if ($paginator->hasPages())
<nav role="navigation" aria-label="صفحه‌بندی">
    <ul>
        <li>
            @if ($paginator->onFirstPage())
                <span aria-disabled="true">قبلی</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev">قبلی</a>
            @endif
        </li>

        @foreach ($elements as $element)
            @if (is_string($element))
                <li><span aria-disabled="true">{{ $element }}</span></li>
            @elseif (is_array($element))
                @foreach ($element as $page => $url)
                    <li>
                        @if ((int) $page === $paginator->currentPage())
                            <span aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}">{{ $page }}</a>
                        @endif
                    </li>
                @endforeach
            @endif
        @endforeach

        <li>
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next">بعدی</a>
            @else
                <span aria-disabled="true">بعدی</span>
            @endif
        </li>
    </ul>
</nav>
@endif
