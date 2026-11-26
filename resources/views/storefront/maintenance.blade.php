<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $storeName }}</title>
    @vite(array_merge(['resources/css/app.css'], array_map(fn ($f) => "resources/css/fonts/{$f}.css", $theme['fontFiles'])))
    @if ($theme['css'])<style>{!! $theme['css'] !!}</style>@endif
    @if ($theme['favicon'])<link rel="icon" href="{{ $theme['favicon'] }}">@endif
</head>
<body class="flex min-h-screen flex-col items-center justify-center bg-soft px-6 text-center text-ink">
    <main class="w-full max-w-lg rounded-panel-lg border border-line bg-surface px-8 py-14">
        @if ($theme['logo'])
            <img src="{{ $theme['logo']['url'] }}" alt="{{ $storeName }}" class="mx-auto h-9 w-auto">
        @else
            <p class="font-heading text-[20px] font-bold tracking-tight">{{ $storeName }}</p>
        @endif
        <h1 class="mt-8 text-[28px] leading-tight">Back soon</h1>
        <p class="mt-4 whitespace-pre-line text-muted">{{ $message }}</p>
    </main>
</body>
</html>
