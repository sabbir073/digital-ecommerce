@extends('storefront.layout')

@section('title', 'Store reviews')
@section('description', 'What customers say about buying from '.$storeName.'.')

@section('content')
    <div class="container-store pb-8">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <ol>
                <li><a href="{{ route('home') }}">Home</a></li>
                <li aria-current="page">Store reviews</li>
            </ol>
        </nav>
        <h1 class="page-headline">What customers say about {{ $storeName }}</h1>
        <p class="mt-3 max-w-prose text-muted">Reviews of the shop as a whole: ordering, payment, delivery and support. Reviews of single products are on each product page.</p>

        @include('storefront.partials.reviews', ['heading' => 'Store reviews'])
    </div>
@endsection
