@php $list = $reviews[$block['id']] ?? collect(); @endphp
@if ($list->isNotEmpty())
    @include('selling.blocks._open')
        <div class="container-selling">
            @if ($d['heading'])
                <h2 class="section-heading" @edit('heading')>{{ $d['heading'] }}</h2>
            @endif
            <ul class="sp-cards mt-7" style="--sp-cols: {{ min(3, $list->count()) }}">
                @foreach ($list as $review)
                    <li class="sp-card text-left">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            @include('storefront.partials.stars', ['rating' => $review->rating, 'class' => 'sp-stars'])
                            @if ($review->verified)
                                <span class="text-[13px] font-semibold">{{ t('Verified buyer') }}</span>
                            @endif
                        </div>
                        @if ($review->title)
                            <p class="mt-3 font-semibold">{{ $review->title }}</p>
                        @endif
                        <p class="mt-2 sp-muted">{{ $review->body }}</p>
                        @if ($photo = $review->photos->first())
                            <img src="{{ $photo->sizeUrl(320) }}" alt="Photo from {{ $review->display_name }}" width="88" height="88" loading="lazy" class="mt-4 h-[88px] w-[88px] rounded-xl object-cover">
                        @endif
                        <p class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-label font-medium">
                            @include('storefront.partials.flag', ['code' => $review->country_code])
                            <span>{{ $review->display_name }}</span>
                            @if ($review->source_label) <span class="sp-muted font-normal">· {{ $review->source_label }}</span> @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif
