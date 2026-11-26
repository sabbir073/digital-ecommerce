<article class="blog-card">
    <a href="{{ $post->url() }}" class="group block">
        <div class="blog-card-media">
            @if ($post->cover)
                <img src="{{ $post->cover->sizeUrl(640) }}" srcset="{{ $post->cover->srcset() }}" sizes="(min-width: 1024px) 380px, (min-width: 640px) 50vw, 100vw"
                     alt="{{ $post->cover->alt ?: '' }}" width="640" height="400" loading="lazy" decoding="async">
            @endif
        </div>
        <p class="mt-4 text-label text-muted">
            @if ($post->category) <span class="font-semibold text-accent-tint-text">{{ $post->category->name }}</span> · @endif
            <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('F j, Y') }}</time>
        </p>
        <h2 class="mt-1.5 text-[22px] leading-snug group-hover:underline">{{ $post->title }}</h2>
        @if ($post->excerpt)
            <p class="mt-2 text-muted">{{ $post->excerpt }}</p>
        @endif
    </a>
</article>
