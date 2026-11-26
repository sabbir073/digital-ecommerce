@extends('storefront.layout')

@section('description', $tagline)

@section('content')
    {{-- The hero carries the page's main heading. Without it the store name does, for screen readers. --}}
    @if (($sections[0]['type'] ?? null) !== 'hero')
        <h1 class="sr-only">{{ $storeName }}</h1>
    @endif
    @foreach ($sections as $section)
        @includeIf('storefront.home.'.str_replace('_', '-', $section['type']), ['s' => $section['settings'], 'data' => $section['data'], 'first' => $loop->first])
    @endforeach
@endsection
