<!DOCTYPE html>
<html lang="en" data-currency="{{ $currencyFormat }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('storefront.partials.seo-head')
    @if ($builder ?? false)
        {{-- The builder canvas: same page and theme, with the editing script instead of the checkout script. --}}
        <meta name="robots" content="noindex, nofollow">
        @vite(['resources/css/app.css', 'resources/css/builder-canvas.css', 'resources/js/admin/builder-canvas.ts'])
    @else
        @vite(['resources/css/app.css', 'resources/js/storefront/app.ts', 'resources/js/storefront/selling.ts'])
    @endif
    @stack('head')
    @include('storefront.partials.theme-head')
</head>
<body class="bg-surface text-ink">
    @unless ($builder ?? false)
        @if ($seoSite['body_start'] !== '')
            {!! $seoSite['body_start'] !!}
        @endif
    @endunless
    <a href="#main" class="skip-link">{{ t('Skip to content') }}</a>

    {{-- No store navigation here, so nothing pulls the customer away from the page. --}}
    <header class="border-b border-line bg-surface">
        <div class="container-selling flex h-16 items-center justify-between gap-4">
            <span class="font-heading text-[20px] font-bold tracking-tight">@include('storefront.partials.logo')</span>
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
        <span>{{ t('Secure card payment by Stripe') }} <button type="button" hidden data-consent-open class="ml-2 inline-flex min-h-11 items-center underline">{{ t('Cookie settings') }}</button></span>
    </footer>

    @if (! ($builder ?? false) && ($chat['onSellingPages'] ?? false))
        {{-- Raised on phones so it does not cover the order bar. --}}
        @include('storefront.partials.chat', ['chatClass' => 'chat-raised'])
    @endif

    @if ($uiText)
        <script type="application/json" id="ui-text">{!! json_encode($uiText, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
    @stack('end')
    @include('storefront.partials.tracking')
    @unless ($builder ?? false)
        @if ($seoSite['body_end'] !== '')
            {!! $seoSite['body_end'] !!}
        @endif
    @endunless
</body>
</html>
