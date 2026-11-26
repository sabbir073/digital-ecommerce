@if ($paginator->hasPages())
    <nav aria-label="{{ t('Pages') }}" class="mt-12 flex items-center justify-center gap-1.5">
        @if ($paginator->onFirstPage())
            <span class="page-btn" aria-disabled="true">{{ t('Previous') }}</span>
        @else
            <a class="page-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ t('Previous') }}</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="px-1 text-muted" aria-hidden="true">{{ $element }}</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="page-btn page-btn-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="page-btn hidden sm:inline-flex" href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="page-btn" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ t('Next') }}</a>
        @else
            <span class="page-btn" aria-disabled="true">{{ t('Next') }}</span>
        @endif
    </nav>
@endif
