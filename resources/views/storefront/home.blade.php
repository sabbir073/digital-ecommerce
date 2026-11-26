@extends('storefront.layout')

@section('description', $tagline)

@section('content')
    @foreach ($sections as $section)
        @includeIf('storefront.home.'.str_replace('_', '-', $section['type']), ['s' => $section['settings'], 'data' => $section['data']])
    @endforeach
@endsection
