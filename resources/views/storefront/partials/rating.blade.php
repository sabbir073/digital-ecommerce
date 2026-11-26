{{-- Average rating and review count. Shown only once a product has approved reviews. --}}
@if (($count ?? 0) > 0)
    <span class="inline-flex items-center gap-1.5 text-label text-muted">
        <span class="rating-stars" style="--rating: {{ number_format((float) $average, 2, '.', '') }}" role="img" aria-label="Rated {{ number_format((float) $average, 1) }} out of 5"></span>
        <span>{{ number_format((float) $average, 1) }} ({{ number_format((int) $count) }})</span>
    </span>
@endif
