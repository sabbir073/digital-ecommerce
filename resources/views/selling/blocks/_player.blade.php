{{-- A video that loads nothing from the video host until the customer presses play. --}}
<div class="sp-video" data-video data-kind="{{ $video['kind'] }}" data-src="{{ $video['src'] }}" data-title="{{ $title ?: 'Video' }}">
    @if ($poster)
        <img src="{{ $poster->sizeUrl(960) }}" srcset="{{ $poster->srcset() }}" sizes="(min-width: 1120px) 1072px, 100vw" alt="" width="{{ $poster->width }}" height="{{ $poster->height }}"
             @if ($eager ?? false) fetchpriority="high" @else loading="lazy" @endif class="h-full w-full object-cover">
    @endif
    <button type="button" class="sp-video-play" data-video-play aria-label="Play video{{ $title ? ': '.$title : '' }}">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg>
    </button>
</div>
