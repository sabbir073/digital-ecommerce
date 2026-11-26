@if ($data['categories']->isNotEmpty())
    <section class="container-store section-gap" aria-labelledby="home-categories">
        <h2 id="home-categories" class="section-heading">{{ $s['heading'] }}</h2>
        <div class="mt-7 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 lg:gap-6">
            @foreach ($data['categories'] as $category)
                <a href="{{ route('category', $category->slug) }}" class="category-tile group">
                    <div class="category-tile-media">
                        @if ($category->image)
                            <img src="{{ $category->image->sizeUrl(640) }}" srcset="{{ $category->image->srcset() }}" sizes="(min-width: 1024px) 280px, 50vw"
                                 alt="{{ $category->image->alt ?? '' }}" width="640" height="480" loading="lazy" decoding="async">
                        @else
                            <span class="font-heading text-[44px] font-bold text-field" aria-hidden="true">{{ mb_substr($category->name, 0, 1) }}</span>
                        @endif
                    </div>
                    <span class="mt-3 flex items-center justify-between gap-2 font-medium">
                        {{ $category->name }}
                        <span class="category-tile-arrow">@include('storefront.partials.icon', ['name' => 'arrow', 'size' => 18])</span>
                    </span>
                </a>
            @endforeach
        </div>
    </section>
@endif
