@extends('selling.layout')

@php
    use App\Support\Money;
    $defaultOffer = collect($offers)->firstWhere('default', true);
    $fromPrice = $defaultOffer['price'] ?? (collect($offers)->min('price') ?? $product->price_cents);
@endphp

@section('title', $page->name)
@if ($product->short_description)
    @section('description', $product->short_description)
@endif

@push('head')
    @if ($preview)
        <meta name="robots" content="noindex">
    @endif
@endpush

@if ($hasOrder)
    @section('header-action')
        <a href="#order" class="btn btn-primary btn-sm" data-order-link>Order now</a>
    @endsection
@endif

@section('content')
    @if ($preview)
        <p class="bg-ink px-6 py-2 text-center text-label font-medium text-white" role="status">Preview of the draft. Customers do not see this version until you publish.</p>
    @endif

    @foreach ($blocks as $block)
        @include('selling.blocks.'.$block['type'], ['block' => $block, 'd' => $block['data'], 'style' => $block['style']])
    @endforeach

    @if ($hasOrder && $defaultOffer)
        {{-- Mobile only: appears once the hero has scrolled away, hides while the order block is on screen. --}}
        <div class="sp-orderbar" data-orderbar hidden>
            <span class="font-heading text-[20px] font-semibold" data-orderbar-price>{{ Money::format($fromPrice) }}</span>
            <a href="#order" class="btn btn-accent btn-sm" data-order-link>Order now</a>
        </div>
    @endif

    <script type="application/json" id="selling-config">@json($config)</script>
@endsection
