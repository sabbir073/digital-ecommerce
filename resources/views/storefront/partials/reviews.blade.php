{{--
    The reviews section of a product page or of the store reviews page (PRD section 12).
    Needs: $summary, $reviews (paginator), $filters, $countries, $filtered, $form, $heading.
--}}
@php
    $base = url()->current();
    $link = fn (array $change) => $base.'?'.http_build_query(array_filter(array_merge(request()->except(['rpage']), $change), fn ($v) => $v !== null && $v !== '')).'#reviews';
@endphp
<section id="reviews" class="section-gap scroll-mt-24" aria-labelledby="reviews-heading">
    <h2 id="reviews-heading" class="section-heading">{{ $heading }}</h2>

    @if ($summary['count'] === 0)
        <p class="mt-4 text-muted">{{ t('No reviews yet. Be the first to write one.') }}</p>
    @else
        <div class="review-summary mt-7">
            <div>
                <p class="font-heading text-[48px] font-semibold leading-none tracking-tight">{{ number_format($summary['average'], 1) }}</p>
                <p class="mt-3">@include('storefront.partials.rating', ['average' => $summary['average'], 'count' => $summary['count']])</p>
                <p class="mt-1 text-label text-muted">{{ $summary['count'] === 1 ? t('Based on 1 review') : t('Based on :count reviews', ['count' => number_format($summary['count'])]) }}</p>
            </div>
            <ul class="grid gap-1.5" aria-label="{{ t('Reviews by star rating') }}">
                @foreach ($summary['stars'] as $star => $total)
                    @php $share = $summary['count'] > 0 ? round($total / $summary['count'] * 100) : 0; $active = $filters['stars'] === $star; @endphp
                    <li>
                        <a href="{{ $link(['stars' => $active ? null : $star]) }}" class="review-bar {{ $active ? 'review-bar-active' : '' }}" @if ($active) aria-current="true" @endif
                           aria-label="{{ t(':stars stars: :count reviews.', ['stars' => $star, 'count' => number_format($total)]) }} {{ $active ? t('Show all ratings') : t('Show only these') }}">
                            <span class="w-14 shrink-0 whitespace-nowrap">{{ $star }} {{ $star === 1 ? 'star' : 'stars' }}</span>
                            <span class="review-bar-track"><span class="review-bar-fill" style="width: {{ $share }}%"></span></span>
                            <span class="w-9 shrink-0 text-right text-muted">{{ number_format($total) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <form method="get" action="{{ $base }}#reviews" class="review-filters mt-8">
            @if ($filters['stars'])
                <input type="hidden" name="stars" value="{{ $filters['stars'] }}">
            @endif
            <div>
                <label class="field-label" for="rv-sort">{{ t('Sort by') }}</label>
                <select class="field" id="rv-sort" name="sort">
                    @foreach (\App\Services\Reviews\ReviewListing::SORTS as $value => $label)
                        <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if (count($countries) > 1)
                <div>
                    <label class="field-label" for="rv-filter-country">{{ t('Country') }}</label>
                    <select class="field" id="rv-filter-country" name="country">
                        <option value="">{{ t('All countries') }}</option>
                        @foreach ($countries as $code => $name)
                            <option value="{{ $code }}" @selected($filters['country'] === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <label class="check min-h-12">
                <input type="checkbox" name="photos" value="1" @checked($filters['photos'])>
                <span>{{ t('With photos') }}</span>
            </label>
            <button type="submit" class="btn btn-secondary btn-sm">{{ t('Apply') }}</button>
            @if ($filtered)
                <a href="{{ $base }}#reviews" class="inline-flex min-h-11 items-center font-medium text-accent hover:underline">{{ t('Clear filters') }}</a>
            @endif
        </form>

        @if ($reviews->isEmpty())
            <p class="mt-8 text-muted">{{ t('No reviews match these filters.') }}</p>
        @else
            <ul class="mt-4">
                @foreach ($reviews as $review)
                    @include('storefront.partials.review-card', ['review' => $review])
                @endforeach
            </ul>
            {{ $reviews->links('storefront.partials.pagination') }}
        @endif
    @endif

    @include('storefront.partials.review-form', ['form' => $form])
</section>
