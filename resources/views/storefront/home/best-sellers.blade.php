@if ($data['products']->isNotEmpty())
    <section class="container-store section-gap" aria-labelledby="home-best-sellers">
        <div class="flex items-end justify-between gap-4">
            <h2 id="home-best-sellers" class="section-heading">{{ $s['heading'] }}</h2>
            <a href="{{ route('products', ['sort' => 'best']) }}" class="inline-flex min-h-11 items-center gap-1.5 font-medium text-accent hover:underline">
                {{ t('View all') }} @include('storefront.partials.icon', ['name' => 'arrow', 'size' => 18])
            </a>
        </div>
        <div class="product-grid mt-7">
            @foreach ($data['products'] as $product)
                @include('storefront.partials.product-card', ['product' => $product])
            @endforeach
        </div>
    </section>
@endif
