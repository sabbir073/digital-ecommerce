@php $reasons = \App\Http\Controllers\Storefront\ReviewController::REPORT_REASONS; @endphp
<li class="review" id="review-{{ $review->id }}">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        @include('storefront.partials.stars', ['rating' => $review->rating])
        @if ($review->pinned)
            <span class="badge">Pinned</span>
        @endif
        @if ($review->verified)
            <span class="review-verified">@include('storefront.partials.icon', ['name' => 'check', 'size' => 14]) Verified buyer</span>
        @endif
    </div>
    @if ($review->title)
        <h3 class="mt-3 text-[17px] font-semibold">{{ $review->title }}</h3>
    @endif
    <p class="mt-2 max-w-prose whitespace-pre-line">{{ $review->body }}</p>

    @if ($review->photos->isNotEmpty())
        <ul class="mt-4 flex flex-wrap gap-2.5">
            @foreach ($review->photos as $photo)
                <li>
                    <a href="{{ $photo->url() }}" target="_blank" rel="noopener" class="review-photo">
                        <img src="{{ $photo->sizeUrl(320) }}" alt="Photo {{ $loop->iteration }} from {{ $review->display_name }}" width="88" height="88" loading="lazy">
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-label text-muted">
        <span class="font-medium text-ink">{{ $review->display_name }}</span>
        @if ($review->countryName())
            <span class="inline-flex items-center gap-1.5">@include('storefront.partials.flag', ['code' => $review->country_code]) {{ $review->countryName() }}</span>
        @endif
        <span aria-hidden="true">·</span>
        <time datetime="{{ $review->writtenAt()->toDateString() }}">{{ $review->writtenAt()->format('F j, Y') }}</time>
        @if ($review->source_label)
            <span aria-hidden="true">·</span>
            <span>{{ $review->source_label }}</span>
        @endif
    </p>

    @if ($review->store_reply)
        <div class="review-reply">
            <p class="text-label font-semibold">Reply from {{ $storeName }}</p>
            <p class="mt-1 whitespace-pre-line">{{ $review->store_reply }}</p>
        </div>
    @endif

    <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-label">
        <form method="post" action="{{ route('reviews.helpful', $review) }}">
            @csrf
            <button type="submit" class="review-action">
                @include('storefront.partials.icon', ['name' => 'check', 'size' => 16])
                Helpful{{ $review->helpful_count > 0 ? ' ('.number_format($review->helpful_count).')' : '' }}
            </button>
        </form>
        <details class="review-report">
            <summary class="review-action">Report</summary>
            <form method="post" action="{{ route('reviews.report', $review) }}" class="mt-3 grid max-w-sm gap-3">
                @csrf
                <div>
                    <label class="field-label" for="report-reason-{{ $review->id }}">Why are you reporting this review?</label>
                    <select class="field" id="report-reason-{{ $review->id }}" name="reason" required>
                        @foreach ($reasons as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="field-label" for="report-details-{{ $review->id }}">Details (optional)</label>
                    <input class="field" id="report-details-{{ $review->id }}" name="details" maxlength="300">
                </div>
                <button type="submit" class="btn btn-secondary btn-sm justify-self-start">Send report</button>
            </form>
        </details>
    </div>
</li>
