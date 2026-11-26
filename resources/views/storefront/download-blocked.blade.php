@extends('storefront.layout')

@section('title', t('Download'))

@section('content')
    <section class="container-store section-gap">
        <div class="mx-auto max-w-lg rounded-panel bg-soft px-6 py-14 text-center">
            <h1 class="text-[26px]">{{ t('Download not available') }}</h1>
            <p class="mt-3 text-muted">{{ $problem }}</p>
            @auth('web')
                <a href="{{ route('account') }}" class="btn btn-primary mt-7">{{ t('Go to my orders') }}</a>
            @else
                <a href="{{ route('home') }}" class="btn btn-primary mt-7">{{ t('Back to the store') }}</a>
            @endauth
        </div>
    </section>
@endsection
