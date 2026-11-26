@extends('storefront.layout')

@section('title', t('Store reviews'))
@section('description', t('What customers say about buying from :store.', ['store' => $storeName]))

@section('content')
    <div class="container-store pb-8">
        <nav class="breadcrumb" aria-label="{{ t('Breadcrumb') }}">
            <ol>
                <li><a href="{{ route('home') }}">{{ t('Home') }}</a></li>
                <li aria-current="page">{{ t('Store reviews') }}</li>
            </ol>
        </nav>
        <h1 class="page-headline">{{ t('What customers say about :store', ['store' => $storeName]) }}</h1>
        <p class="mt-3 max-w-prose text-muted">{{ t('Reviews of the shop as a whole: ordering, payment, delivery and support. Reviews of single products are on each product page.') }}</p>

        @include('storefront.partials.reviews', ['heading' => t('Store reviews')])
    </div>
@endsection
