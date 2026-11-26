{{--
    Title, description and everything search engines and social sites read.
    A page that is an SEO item (product, category, selling page, page, post, home) passes $seo, built from its
    SEO panel and the site-wide templates. Other pages (cart, checkout, account) are kept out of search results.
--}}
@php
    $seo = $seo ?? null;
    $private = ($preview ?? false) || ($builder ?? false);
@endphp
@if ($seo)
    <title>{{ $seo['title'] }}</title>
    @if ($seo['description'] !== '')
        <meta name="description" content="{{ $seo['description'] }}">
    @endif
    @unless ($private)
        <link rel="canonical" href="{{ $seo['canonical'] }}">
        @if ($seo['robots'])
            <meta name="robots" content="{{ $seo['robots'] }}">
        @endif
        <meta property="og:site_name" content="{{ $storeName }}">
        <meta property="og:type" content="{{ $seo['og']['type'] }}">
        <meta property="og:title" content="{{ $seo['og']['title'] }}">
        @if ($seo['og']['description'])
            <meta property="og:description" content="{{ $seo['og']['description'] }}">
        @endif
        <meta property="og:url" content="{{ $seo['canonical'] }}">
        @if ($seo['og']['image'])
            <meta property="og:image" content="{{ $seo['og']['image'] }}">
            @if ($seo['og']['image_alt'])
                <meta property="og:image:alt" content="{{ $seo['og']['image_alt'] }}">
            @endif
        @endif
        <meta name="twitter:card" content="{{ $seo['og']['image'] ? 'summary_large_image' : 'summary' }}">
        <meta name="twitter:title" content="{{ $seo['og']['title'] }}">
        @if ($seo['og']['description'])
            <meta name="twitter:description" content="{{ $seo['og']['description'] }}">
        @endif
        @if ($seo['og']['image'])
            <meta name="twitter:image" content="{{ $seo['og']['image'] }}">
        @endif
        @if ($seo['custom_tags'] !== '')
            {!! $seo['custom_tags'] !!}
        @endif
        @foreach ($seo['json_ld'] as $data)
            <script type="application/ld+json">{!! json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
        @endforeach
    @endunless
@else
    <title>@hasSection('title')@yield('title') · @endif{{ $storeName }}</title>
    @hasSection('description')
        <meta name="description" content="@yield('description')">
    @endif
    @unless ($private)
        <meta name="robots" content="noindex, follow">
    @endunless
@endif
@unless ($builder ?? false)
    @if ($seoSite['verification'] !== '')
        {!! $seoSite['verification'] !!}
    @endif
    @if ($seoSite['head'] !== '')
        {!! $seoSite['head'] !!}
    @endif
@endunless
