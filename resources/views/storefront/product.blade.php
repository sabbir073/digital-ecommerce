@extends('storefront.layout')

@php
    use App\Support\Money;

    $initial = $product->variants->first(fn ($v) => ! $product->track_stock || $v->stock > 0) ?? $product->variants->first();
    $selected = $initial ? $initial->optionValues->pluck('id')->all() : [];
    $price = $initial ? (int) $initial->price_cents : (int) $product->price_cents;
    $compare = $initial ? $initial->compare_at_cents : $product->compare_at_cents;
    $onSale = $compare !== null && $compare > $price;
    $stock = $product->availableStock($initial);
    $soldOut = $stock === 0;
    $canBuy = Route::has('cart.add');
@endphp

@section('title', $product->seo?->meta_title ?: $product->name)
@section('description', $product->seo?->meta_description ?: Str::limit((string) $product->short_description, 155))

@push('head')
    @vite('resources/js/storefront/product.ts')
@endpush

@section('content')
    <div class="container-store mt-8 lg:mt-10" data-product>
        <nav aria-label="{{ t('Breadcrumb') }}" class="breadcrumb">
            <a href="{{ route('home') }}">{{ t('Home') }}</a>
            @if ($product->category?->parent)
                <span aria-hidden="true">/</span><a href="{{ route('category', $product->category->parent->slug) }}">{{ $product->category->parent->name }}</a>
            @endif
            @if ($product->category)
                <span aria-hidden="true">/</span><a href="{{ route('category', $product->category->slug) }}">{{ $product->category->name }}</a>
            @endif
            <span aria-hidden="true">/</span><span aria-current="page">{{ $product->name }}</span>
        </nav>

        <div class="mt-6 grid gap-8 lg:grid-cols-[1.1fr_1fr] lg:gap-14">
            {{-- Gallery: one main image and thumbnails. The main strip scrolls sideways, so touch screens can swipe. --}}
            <div class="lg:sticky lg:top-24 lg:self-start">
                <div class="gallery-track" data-gallery-track tabindex="0" role="region" aria-roledescription="carousel" aria-label="{{ $product->name }} images">
                    @forelse ($gallery as $index => $image)
                        <div class="gallery-slide" data-gallery-slide data-media-id="{{ $image->id }}" role="group" aria-roledescription="slide" aria-label="{{ $index + 1 }} of {{ $gallery->count() }}">
                            <img src="{{ $image->sizeUrl(960) }}" srcset="{{ $image->srcset() }}" sizes="(min-width: 1024px) 600px, 100vw"
                                 alt="{{ $image->alt ?: $product->name }}" width="{{ $image->width ?: 960 }}" height="{{ $image->height ?: 960 }}"
                                 @if ($index === 0) fetchpriority="high" @else loading="lazy" @endif decoding="async">
                        </div>
                    @empty
                        <div class="gallery-slide" aria-hidden="true"></div>
                    @endforelse
                </div>

                @if ($gallery->count() > 1)
                    <div class="gallery-thumbs" role="group" aria-label="{{ t('Choose an image') }}">
                        @foreach ($gallery as $index => $image)
                            <button type="button" class="gallery-thumb" data-gallery-thumb="{{ $index }}" aria-label="Show image {{ $index + 1 }}" @if ($index === 0) aria-current="true" @endif>
                                <img src="{{ $image->sizeUrl(320) }}" alt="" width="160" height="160" loading="lazy" decoding="async">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                @if ($product->category)
                    <a href="{{ route('category', $product->category->slug) }}" class="text-label font-semibold uppercase tracking-wide text-muted hover:text-ink">{{ $product->category->name }}</a>
                @endif
                <h1 class="mt-2 text-[30px] font-bold md:text-[38px]">{{ $product->name }}</h1>

                @if ($product->rating_count > 0)
                    <a href="#reviews" class="mt-3 inline-flex min-h-11 items-center hover:underline">
                        @include('storefront.partials.rating', ['average' => $product->rating_average, 'count' => $product->rating_count])
                    </a>
                @else
                    <a href="#write-review" class="mt-3 inline-flex min-h-11 items-center text-label text-muted hover:text-ink hover:underline">{{ t('Write the first review') }}</a>
                @endif

                <p class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2">
                    <span class="font-heading text-[28px] font-semibold tracking-tight" data-price>{{ Money::format($price) }}</span>
                    <s class="text-[17px] text-muted" data-compare @if (! $onSale) hidden @endif>{{ $onSale ? Money::format($compare) : '' }}</s>
                    <span class="badge" data-save @if (! $onSale) hidden @endif>{{ $onSale ? t('Save :amount', ['amount' => Money::format($compare - $price)]) : '' }}</span>
                </p>

                @if ($product->short_description)
                    <p class="mt-5 max-w-prose text-[16px] text-muted">{{ $product->short_description }}</p>
                @endif

                {{-- Buying starts with the cart (Phase 3); until its routes exist this is a plain picker with no dead buttons. --}}
                @if ($canBuy)
                    <form class="mt-7 space-y-6" action="{{ route('cart.add') }}" method="post" data-buy-form data-cart-form data-cart-opens>
                    @csrf
                @else
                    <div class="mt-7 space-y-6" data-buy-form>
                @endif
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <input type="hidden" name="variant_id" value="{{ $initial?->id }}" data-variant-input>

                    @foreach ($product->options as $option)
                        @php $current = $option->values->first(fn ($v) => in_array($v->id, $selected, true)); @endphp
                        <fieldset data-option="{{ $option->id }}">
                            <legend class="text-label font-medium">{{ $option->name }}: <span class="text-muted" data-option-label>{{ $current?->value }}</span></legend>
                            <div class="mt-2.5 flex flex-wrap gap-2.5">
                                @foreach ($option->values as $value)
                                    @if ($option->display === 'swatch' && $value->swatch_hex)
                                        <label class="swatch" title="{{ $value->value }}">
                                            <input type="radio" class="sr-only" name="option_{{ $option->id }}" value="{{ $value->id }}" data-label="{{ $value->value }}" @checked(in_array($value->id, $selected, true))>
                                            <span class="swatch-dot" style="background: {{ $value->swatch_hex }}"></span>
                                            <span class="sr-only">{{ $value->value }}</span>
                                        </label>
                                    @else
                                        <label class="chip">
                                            <input type="radio" class="sr-only" name="option_{{ $option->id }}" value="{{ $value->id }}" data-label="{{ $value->value }}" @checked(in_array($value->id, $selected, true))>
                                            <span>{{ $value->value }}</span>
                                        </label>
                                    @endif
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach

                    <div class="flex flex-wrap items-end gap-x-6 gap-y-4">
                        <div>
                            <label for="qty" class="field-label">{{ t('Quantity') }}</label>
                            <div class="qty">
                                <button type="button" class="qty-btn" data-qty-minus aria-label="{{ t('Decrease quantity') }}">@include('storefront.partials.icon', ['name' => 'minus', 'size' => 18])</button>
                                <input id="qty" name="quantity" type="number" inputmode="numeric" min="1" @if ($stock !== null) max="{{ max(1, $stock) }}" @endif value="1" data-qty @disabled($soldOut)>
                                <button type="button" class="qty-btn" data-qty-plus aria-label="{{ t('Increase quantity') }}">@include('storefront.partials.icon', ['name' => 'plus', 'size' => 18])</button>
                            </div>
                        </div>
                        <p class="min-h-12 content-center text-label" data-stock-note aria-live="polite">
                            @if ($soldOut)
                                <span class="font-semibold text-ink">{{ t('Out of stock') }}</span>
                            @elseif ($stock !== null && $stock <= 5)
                                <span class="font-semibold text-ink">Only {{ $stock }} left</span>
                            @else
                                <span class="inline-flex items-center gap-1.5 text-muted">@include('storefront.partials.icon', ['name' => 'check', 'size' => 16]) In stock</span>
                            @endif
                        </p>
                    </div>

                    <div class="flex items-baseline justify-between border-t border-line pt-5">
                        <span class="text-muted">{{ t('Total') }}</span>
                        <span class="font-heading text-[22px] font-semibold" data-total>{{ Money::format($price) }}</span>
                    </div>

                    @if ($canBuy)
                        <div class="grid gap-3 sm:grid-cols-2">
                            <button type="submit" name="intent" value="cart" class="btn btn-primary" data-buy-button @disabled($soldOut)>
                                <span data-add-label>{{ t('Add to cart') }} · {{ Money::format($price) }}</span>
                            </button>
                            <button type="submit" name="intent" value="buy" class="btn btn-accent" data-buy-button @disabled($soldOut)>{{ t('Buy now') }}</button>
                        </div>
                    @endif
                @if ($canBuy) </form> @else </div> @endif

                @if ($sellingPage)
                    <a href="{{ $sellingPage->url() }}" class="mt-5 inline-flex min-h-11 items-center gap-2 font-medium text-accent hover:underline">
                        {{ t('See the full story and special packs') }}
                        @include('storefront.partials.icon', ['name' => 'arrow', 'size' => 18])
                    </a>
                @endif

                <div class="mt-8 border-t border-line">
                    @if ($product->description)
                        <details class="accordion" open>
                            <summary>Details @include('storefront.partials.icon', ['name' => 'chevron', 'size' => 20])</summary>
                            <div class="accordion-body">{!! nl2br(e($product->description)) !!}</div>
                        </details>
                    @endif
                    @if ($deliveryText)
                        <details class="accordion">
                            <summary>Delivery @include('storefront.partials.icon', ['name' => 'chevron', 'size' => 20])</summary>
                            <div class="accordion-body">{!! nl2br(e($deliveryText)) !!}</div>
                        </details>
                    @endif
                    @if ($returnsText)
                        <details class="accordion">
                            <summary>Returns @include('storefront.partials.icon', ['name' => 'chevron', 'size' => 20])</summary>
                            <div class="accordion-body">{!! nl2br(e($returnsText)) !!}</div>
                        </details>
                    @endif
                </div>
            </div>
        </div>

        @include('storefront.partials.reviews', ['heading' => t('Customer reviews')])

        @if ($related->isNotEmpty())
            <section class="section-gap" aria-labelledby="related-heading">
                <h2 id="related-heading" class="section-heading">{{ t('You may also like') }}</h2>
                <div class="product-grid mt-7">
                    @foreach ($related as $item)
                        @include('storefront.partials.product-card', ['product' => $item])
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    <script type="application/json" id="product-state">@json($state)</script>
@endsection
