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
                        @include('storefront.partials.rating', ['rating' => $review->rating])
                        @if ($review->title)
                            <p class="mt-3 font-semibold">{{ $review->title }}</p>
                        @endif
                        <p class="mt-2 sp-muted">{{ $review->body }}</p>
                        <p class="mt-4 text-label font-medium">
                            @if ($review->flag()) <span aria-hidden="true">{{ $review->flag() }}</span> @endif
                            {{ $review->display_name }}
                            @if ($review->source_label) <span class="sp-muted font-normal">· {{ $review->source_label }}</span> @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif
