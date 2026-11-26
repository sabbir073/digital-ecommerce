{{-- Theme settings: fonts other than the two defaults load only when chosen; colours override the defaults. --}}
@if ($theme['fontFiles'])
    @vite(array_map(fn ($f) => "resources/css/fonts/{$f}.css", $theme['fontFiles']))
@endif
@if ($theme['css'])
    <style>{!! $theme['css'] !!}</style>
@endif
@if ($theme['favicon'])
    <link rel="icon" href="{{ $theme['favicon'] }}">
@endif
