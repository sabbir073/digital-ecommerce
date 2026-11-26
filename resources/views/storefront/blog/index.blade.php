@extends('storefront.layout')

@section('title', $current ? $current->name.' · Blog' : t('Blog'))
@section('description', t('News, guides and updates from :store.', ['store' => $storeName]))

@section('content')
    <div class="container-store py-10 lg:py-14">
        <h1 class="page-headline">{{ $current ? $current->name : t('Blog') }}</h1>
        @if ($tag !== '')
            <p class="mt-3 text-muted">{{ t('Posts tagged “:tag”.', ['tag' => $tag]) }} <a href="{{ route('blog.index') }}" class="font-medium text-accent hover:underline">{{ t('Show all posts') }}</a></p>
        @endif

        @if ($categories->isNotEmpty())
            <nav aria-label="{{ t('Blog categories') }}" class="mt-6 flex flex-wrap gap-2">
                <a href="{{ route('blog.index') }}" class="chip {{ $current ? '' : 'chip-on' }}" @if (! $current) aria-current="page" @endif>{{ t('All posts') }}</a>
                @foreach ($categories as $category)
                    <a href="{{ route('blog.category', $category->slug) }}" class="chip {{ $current?->id === $category->id ? 'chip-on' : '' }}" @if ($current?->id === $category->id) aria-current="page" @endif>{{ $category->name }}</a>
                @endforeach
            </nav>
        @endif

        @if ($posts->isEmpty())
            <div class="mt-10 rounded-panel bg-soft px-6 py-14 text-center">
                <p class="font-heading text-[22px] font-semibold">{{ t('No posts here yet') }}</p>
                <p class="mt-2 text-muted">{{ t('Check back soon, or have a look at the store in the meantime.') }}</p>
                <a href="{{ route('products') }}" class="btn btn-primary mt-6">{{ t('Shop all products') }}</a>
            </div>
        @else
            <div class="mt-10 grid gap-x-8 gap-y-12 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($posts as $post)
                    @include('storefront.blog._card', ['post' => $post])
                @endforeach
            </div>
            {{ $posts->links('storefront.partials.pagination') }}
        @endif
    </div>
@endsection
