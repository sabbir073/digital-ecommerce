<!DOCTYPE html>
<html lang="en" data-currency="{{ $currencyFormat }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('storefront.partials.seo-head')
    @if ($builder ?? false)
        {{-- The page builder's canvas: the same page and theme, with the editing script instead of the shop's. --}}
        @vite(['resources/css/app.css', 'resources/css/builder-canvas.css', 'resources/js/admin/builder-canvas.ts'])
    @else
        @vite(['resources/css/app.css', 'resources/js/storefront/app.ts'])
    @endif
    @include('storefront.partials.theme-head')
    @stack('head')
</head>
<body class="flex min-h-screen flex-col bg-surface text-ink grid-{{ $theme['density'] }}">
    @unless ($builder ?? false)
        @if ($seoSite['body_start'] !== '')
            {!! $seoSite['body_start'] !!}
        @endif
    @endunless
    <a href="#main" class="skip-link">{{ t('Skip to content') }}</a>

    @include('storefront.partials.header')

    <main id="main" class="flex-1">
        @if (session('success') || session('error'))
            <div class="container-store mt-6">
                <p class="notice {{ session('error') ? 'notice-error' : '' }}" role="{{ session('error') ? 'alert' : 'status' }}">{{ session('error') ?? session('success') }}</p>
            </div>
        @endif
        @yield('content')
    </main>

    @include('storefront.partials.footer')

    {{-- Slide-in cart. Its content is fetched when it opens; without JavaScript the cart icon opens the cart page. --}}
    <div class="drawer" data-cart-drawer data-url="{{ route('cart.panel') }}" hidden>
        <div class="drawer-backdrop" data-cart-close></div>
        <aside class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="cart-drawer-title" tabindex="-1">
            <div class="flex items-center justify-between border-b border-line px-6 py-4">
                <h2 id="cart-drawer-title" class="text-[20px]">{{ t('Your cart') }}</h2>
                <button type="button" class="icon-btn" data-cart-close aria-label="{{ t('Close cart') }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <div class="drawer-body" data-cart-root aria-live="polite"></div>
        </aside>
    </div>
    @unless ($builder ?? false)
        @include('storefront.partials.chat')
    @endunless

    @if ($uiText)
        <script type="application/json" id="ui-text">{!! json_encode($uiText, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
    @stack('end')
    @unless ($builder ?? false)
        @if ($seoSite['body_end'] !== '')
            {!! $seoSite['body_end'] !!}
        @endif
    @endunless
</body>
</html>
