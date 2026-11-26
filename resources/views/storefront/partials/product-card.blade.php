@php
    /** @var \App\Models\Product $product */
    $image = $product->images->first();
    $from = $product->fromPriceCents();
    $varies = $product->hasVariants() && $product->variants->min('price_cents') !== $product->variants->max('price_cents');
    $soldOut = ! $product->isInStock();
@endphp
<a href="{{ route('product', $product->slug) }}" class="product-card group">
    <div class="product-card-media">
        @if ($image)
            <img src="{{ $image->sizeUrl(640) }}" srcset="{{ $image->srcset() }}" sizes="(min-width: 1024px) 280px, (min-width: 640px) 33vw, 50vw"
                 alt="{{ $image->alt ?: $product->name }}" width="640" height="640" loading="lazy" decoding="async">
        @endif
        @if ($theme['cards']['badges'])
            @if ($soldOut)
                <span class="badge absolute left-3 top-3 bg-surface text-ink">Sold out</span>
            @elseif ($product->isOnSale())
                <span class="badge absolute left-3 top-3">Sale</span>
            @endif
        @endif
    </div>
    <div class="mt-3.5">
        @if ($product->category)
            <p class="text-label text-muted">{{ $product->category->name }}</p>
        @endif
        <h3 class="mt-0.5 font-body text-[15px] font-medium leading-snug tracking-normal group-hover:underline">{{ $product->name }}</h3>
        @if ($theme['cards']['ratings'])
            @include('storefront.partials.rating', ['average' => $product->rating_average, 'count' => $product->rating_count])
        @endif
        <p class="mt-1.5 flex flex-wrap items-baseline gap-x-2">
            <span class="font-semibold">@if ($varies)<span class="font-normal text-muted">From</span> @endif{{ \App\Support\Money::format($from) }}</span>
            @if ($product->isOnSale() && $theme['cards']['compare'])
                <s class="text-label text-muted">{{ \App\Support\Money::format($product->compare_at_cents) }}</s>
            @endif
        </p>
    </div>
</a>
