@extends('storefront.layout')

@section('title', t('Cart'))

@section('content')
    <div class="container-store mt-8 lg:mt-10">
        <h1 class="section-heading">{{ t('Your cart') }}</h1>
        <div class="mt-6 max-w-2xl" data-cart-root data-cart-page>
            @include('storefront.cart.panel', ['summary' => $summary])
        </div>
    </div>
@endsection
