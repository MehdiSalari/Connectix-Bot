@include('setup.partials.checks')

<div class="actions">
    <a href="{{ route('setup.show', ['step' => $step->value]) }}">
        <button type="button" class="secondary">بررسی دوباره</button>
    </a>

    <a href="{{ route('setup.show', ['step' => $step->next()->value]) }}">
        <button type="button">رفتن به {{ $step->next()->label() }}</button>
    </a>
</div>
