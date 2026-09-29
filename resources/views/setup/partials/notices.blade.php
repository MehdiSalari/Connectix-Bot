@if (session('status'))
    <div class="alert ok">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="alert bad">{{ $errors->first() }}</div>
@endif

@if (session('log'))
    <div class="log">{{ session('log') }}</div>
@endif
