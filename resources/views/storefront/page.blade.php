@extends('storefront.layout')

@section('title', $page->title)

@push('head')
    @if ($preview || ($builder ?? false))
        <meta name="robots" content="noindex, nofollow">
    @endif
@endpush

@section('content')
    @if ($preview)
        <p class="bg-ink px-6 py-2 text-center text-label font-medium text-white" role="status">Preview of the draft. Visitors do not see this version until you publish.</p>
    @endif

    {{-- A page that does not open with a hero still needs its one main heading. --}}
    @if (($blocks[0]['type'] ?? null) !== 'hero' || ($blocks[0]['data']['headline'] ?? '') === '')
        <div class="container-selling pt-12"><h1 class="text-[36px] md:text-[44px]">{{ $page->title }}</h1></div>
    @endif

    @if ($builder ?? false)
        <div id="builder-blocks">@include('selling._blocks')</div>
    @else
        @include('selling._blocks')
    @endif
@endsection
