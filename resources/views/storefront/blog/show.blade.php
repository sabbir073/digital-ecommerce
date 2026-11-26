@extends('storefront.layout')

@section('title', $post->title)
@if ($post->excerpt)
    @section('description', $post->excerpt)
@endif

@push('head')
    @if ($preview)
        <meta name="robots" content="noindex, nofollow">
    @endif
@endpush

@section('content')
    @if ($preview)
        <p class="bg-ink px-6 py-2 text-center text-label font-medium text-white" role="status">Preview. Visitors cannot see this post {{ $post->status === 'published' ? 'until its publish date' : 'until you publish it' }}.</p>
    @endif

    <article class="container-store py-10 lg:py-14">
        <div class="mx-auto max-w-[760px]">
            <nav aria-label="Breadcrumb" class="text-label text-muted">
                <a href="{{ route('blog.index') }}" class="hover:underline">Blog</a>
                @if ($post->category)
                    <span aria-hidden="true"> / </span><a href="{{ route('blog.category', $post->category->slug) }}" class="hover:underline">{{ $post->category->name }}</a>
                @endif
            </nav>
            <h1 class="mt-4 text-[34px] leading-tight md:text-[44px]">{{ $post->title }}</h1>
            <p class="mt-4 text-muted">
                @if ($post->author) By {{ $post->author->name }} · @endif
                @if ($post->published_at) <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('F j, Y') }}</time> · @endif
                {{ $post->readingMinutes() }} min read
            </p>
        </div>

        @if ($post->cover)
            <img src="{{ $post->cover->sizeUrl(1600) }}" srcset="{{ $post->cover->srcset() }}" sizes="(min-width: 1024px) 960px, 100vw" alt="{{ $post->cover->alt ?: '' }}"
                 width="{{ $post->cover->width }}" height="{{ $post->cover->height }}" fetchpriority="high" class="mx-auto mt-8 aspect-[16/9] w-full max-w-[960px] rounded-panel object-cover">
        @endif

        <div class="mx-auto mt-10 max-w-[760px]">
            {{-- Cleaned by HtmlSanitizer when saved and again here: only basic text tags survive. --}}
            <div class="prose-store">{!! App\Support\HtmlSanitizer::clean($post->body) !!}</div>

            @if (! empty($post->tags))
                <ul class="mt-10 flex flex-wrap gap-2" aria-label="Tags">
                    @foreach ($post->tags as $tag)
                        <li><a href="{{ route('blog.index', ['tag' => $tag]) }}" class="chip">{{ $tag }}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </article>

    @if ($more->isNotEmpty())
        <section class="container-store pb-6" aria-labelledby="more-posts">
            <h2 id="more-posts" class="section-heading">More from the blog</h2>
            <div class="mt-8 grid gap-x-8 gap-y-12 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($more as $other)
                    @include('storefront.blog._card', ['post' => $other])
                @endforeach
            </div>
        </section>
    @endif
@endsection
