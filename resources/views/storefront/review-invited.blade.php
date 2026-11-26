@extends('storefront.layout')

@section('title', 'Review '.$product->name)

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="container-store pb-16">
        <div class="mx-auto max-w-[720px] pt-10">
            <p class="text-label font-semibold uppercase tracking-wide text-muted">Order {{ $item->order->number }}</p>
            <h1 class="page-headline mt-2">How was {{ $product->name }}?</h1>
            <div class="mt-6 flex items-center gap-4">
                @if ($image = $product->images->first())
                    <img src="{{ $image->sizeUrl(320) }}" alt="" width="72" height="72" class="h-[72px] w-[72px] rounded-2xl object-cover">
                @endif
                <div>
                    <p class="font-semibold">{{ $item->name }}</p>
                    @if ($item->variant_label)
                        <p class="text-label text-muted">{{ $item->variant_label }}</p>
                    @endif
                </div>
            </div>

            @if ($done)
                <p class="notice mt-8" role="status">You have reviewed this item. Thank you for taking the time.</p>
                <a href="{{ route('product', $product->slug) }}#reviews" class="btn btn-secondary mt-6">See the reviews</a>
            @else
                @include('storefront.partials.review-form', ['form' => $form])
            @endif

            @if ($others->isNotEmpty())
                <h2 class="mt-14 text-[20px] font-semibold">Other items from this order</h2>
                <ul class="mt-3 divide-y divide-line border-y border-line">
                    @foreach ($others as $other)
                        <li class="flex items-center justify-between gap-4 py-3">
                            <span>{{ $other->orderItem->name }}</span>
                            <a href="{{ $other->url() }}" class="btn btn-secondary btn-sm shrink-0">Review this item</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection
