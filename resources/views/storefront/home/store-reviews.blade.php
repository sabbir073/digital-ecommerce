@if ($data['reviews']->isNotEmpty())
    <section class="container-store section-gap" aria-labelledby="home-store-reviews">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 id="home-store-reviews" class="section-heading">{{ $s['heading'] }}</h2>
                <p class="mt-2">@include('storefront.partials.rating', ['average' => $data['summary']['average'], 'count' => $data['summary']['count']])</p>
            </div>
            <a href="{{ route('reviews') }}" class="inline-flex min-h-11 items-center gap-1.5 font-medium text-accent hover:underline">
                Read all reviews @include('storefront.partials.icon', ['name' => 'arrow', 'size' => 18])
            </a>
        </div>
        <ul class="mt-7 grid gap-5 md:grid-cols-3">
            @foreach ($data['reviews'] as $review)
                <li class="rounded-[20px] bg-soft p-6">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        @include('storefront.partials.stars', ['rating' => $review->rating])
                        @if ($review->verified)
                            <span class="review-verified">Verified buyer</span>
                        @endif
                    </div>
                    @if ($review->title)
                        <p class="mt-3 font-semibold">{{ $review->title }}</p>
                    @endif
                    <p class="mt-2 text-muted">{{ \Illuminate\Support\Str::limit($review->body, 220) }}</p>
                    <p class="mt-4 flex flex-wrap items-center gap-x-2 text-label font-medium">
                        @include('storefront.partials.flag', ['code' => $review->country_code])
                        {{ $review->display_name }}
                    </p>
                </li>
            @endforeach
        </ul>
    </section>
@endif
