<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · {{ $storeName }}</title>
    @hasSection('description')
        <meta name="description" content="@yield('description')">
    @endif
    @vite(['resources/css/app.css', 'resources/js/storefront/app.ts', 'resources/js/storefront/selling.ts'])
    @stack('head')
</head>
<body class="bg-surface text-ink">
    <a href="#main" class="skip-link">Skip to content</a>

    {{-- No store navigation here, so nothing pulls the customer away from the page. --}}
    <header class="border-b border-line bg-surface">
        <div class="container-selling flex h-16 items-center justify-between gap-4">
            <span class="font-heading text-[20px] font-bold tracking-tight">{{ $storeName }}</span>
            @hasSection('header-action')
                @yield('header-action')
            @endif
        </div>
    </header>

    <main id="main">
        @yield('content')
    </main>

    <footer class="container-selling flex flex-wrap justify-between gap-3 py-7 text-[14px] text-muted">
        <span>&copy; {{ date('Y') }} {{ $storeName }}</span>
        <span>Secure card payment by Stripe</span>
    </footer>

    @stack('end')
</body>
</html>
