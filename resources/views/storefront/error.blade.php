@extends('storefront.layout')

@section('title', $title)

@section('content')
    <section class="container-store section-gap text-center">
        <p class="badge">Error {{ $status }}</p>
        <h1 class="page-headline mt-5">{{ $title }}</h1>
        <p class="mx-auto mt-4 max-w-md text-muted">{{ $text }}</p>
        <a href="{{ url('/') }}" class="btn btn-primary mt-8">{{ t('Back to home') }}</a>
    </section>
@endsection
