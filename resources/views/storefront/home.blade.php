@extends('storefront.layout')

@section('description', $tagline)

@section('content')
    <section class="container-store section-gap">
        <div class="rounded-panel-lg bg-soft px-6 py-16 text-center md:px-12 md:py-24">
            <h1 class="page-headline mx-auto max-w-3xl">{{ $storeName }}</h1>
            <p class="mx-auto mt-5 max-w-xl text-[17px] text-muted">{{ $tagline }}</p>
        </div>
    </section>
@endsection
