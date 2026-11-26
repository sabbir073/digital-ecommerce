<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title') · @endif{{ $storeName }}</title>
    @hasSection('description')
        <meta name="description" content="@yield('description')">
    @endif
    @vite(['resources/css/app.css', 'resources/js/storefront/app.ts'])
    @stack('head')
</head>
<body class="flex min-h-screen flex-col bg-surface text-ink">
    <a href="#main" class="skip-link">Skip to content</a>

    @include('storefront.partials.header')

    <main id="main" class="flex-1">
        @yield('content')
    </main>

    @include('storefront.partials.footer')
</body>
</html>
