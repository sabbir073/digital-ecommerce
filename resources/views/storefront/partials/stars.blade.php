{{-- The star rating of one review. --}}
<span class="rating-stars {{ $class ?? '' }}" style="--rating: {{ (int) $rating }}" role="img" aria-label="{{ (int) $rating }} out of 5 stars"></span>
