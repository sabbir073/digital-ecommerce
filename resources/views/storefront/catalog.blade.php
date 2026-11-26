@extends('storefront.layout')

@section('title', $title)

@section('content')
    <div class="container-store mt-8 lg:mt-10">
        <nav aria-label="{{ t('Breadcrumb') }}" class="breadcrumb">
            <a href="{{ route('home') }}">{{ t('Home') }}</a>
            @if ($category?->parent)
                <span aria-hidden="true">/</span><a href="{{ route('category', $category->parent->slug) }}">{{ $category->parent->name }}</a>
            @endif
            <span aria-hidden="true">/</span><span aria-current="page">{{ $category?->name ?? ($search !== null ? t('Search') : t('All products')) }}</span>
        </nav>

        <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="section-heading">{{ $title }}</h1>
                <p class="mt-2 text-muted" aria-live="polite">{{ $products->total() }} {{ Str::plural('product', $products->total()) }}</p>
            </div>

            @if ($category && $category->children->isNotEmpty())
                <ul class="flex flex-wrap gap-2" aria-label="{{ t('Sub-categories') }}">
                    @foreach ($category->children as $child)
                        <li><a href="{{ route('category', $child->slug) }}" class="chip-link">{{ $child->name }}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>

        <form action="{{ $action }}" method="get" class="filter-bar mt-7" aria-label="{{ t('Filter and sort') }}">
            @if ($search !== null)
                <div class="filter-cell filter-cell-wide">
                    <label class="field-label" for="f-q">{{ t('Search') }}</label>
                    <input class="field" id="f-q" type="search" name="q" value="{{ $search }}" placeholder="{{ t('Search products') }}" maxlength="100">
                </div>
            @endif

            @unless ($category)
                <div class="filter-cell">
                    <label class="field-label" for="f-category">{{ t('Category') }}</label>
                    <select class="field" id="f-category" name="category">
                        <option value="">{{ t('All categories') }}</option>
                        @foreach ($allCategories as $top)
                            <option value="{{ $top->slug }}" @selected($filters['category'] === $top->slug)>{{ $top->name }}</option>
                            @foreach ($top->children as $child)
                                <option value="{{ $child->slug }}" @selected($filters['category'] === $child->slug)>&nbsp;&nbsp;{{ $child->name }}</option>
                            @endforeach
                        @endforeach
                    </select>
                </div>
            @endunless

            <div class="filter-cell">
                <label class="field-label" for="f-min">{{ t('Min price ($)') }}</label>
                <input class="field" id="f-min" type="number" name="min" min="0" step="0.01" inputmode="decimal" value="{{ $filters['min_input'] }}" placeholder="0">
            </div>
            <div class="filter-cell">
                <label class="field-label" for="f-max">{{ t('Max price ($)') }}</label>
                <input class="field" id="f-max" type="number" name="max" min="0" step="0.01" inputmode="decimal" value="{{ $filters['max_input'] }}" placeholder="{{ t('Any') }}">
            </div>
            <div class="filter-cell">
                <label class="field-label" for="f-sort">{{ t('Sort by') }}</label>
                <select class="field" id="f-sort" name="sort" data-autosubmit>
                    @foreach ($sorts as $value => $label)
                        <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-cell filter-cell-check">
                <label class="check">
                    <input type="checkbox" name="in_stock" value="1" @checked($filters['in_stock']) data-autosubmit>
                    <span>{{ t('In stock only') }}</span>
                </label>
            </div>
            <div class="filter-cell filter-cell-actions">
                <button type="submit" class="btn btn-primary btn-sm">{{ t('Apply') }}</button>
                @if ($filters['active'] > 0)
                    <a href="{{ $action }}{{ $search !== null && $search !== '' ? '?q='.urlencode($search) : '' }}" class="inline-flex min-h-11 items-center px-2 font-medium text-accent hover:underline">{{ t('Clear filters') }}</a>
                @endif
            </div>
        </form>

        @if ($products->isEmpty())
            <div class="mt-10 rounded-panel bg-soft px-6 py-16 text-center">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-surface text-muted">
                    @include('storefront.partials.icon', ['name' => 'search', 'size' => 26])
                </span>
                <h2 class="mt-5 text-[22px]">{{ t('No products found') }}</h2>
                <p class="mx-auto mt-2 max-w-sm text-muted">
                    @if ($search !== null && $search !== '')
                        {{ t('Nothing matches “:search”. Check the spelling or try a different word.', ['search' => $search]) }}
                    @else
                        {{ t('Nothing matches these filters. Try removing one.') }}
                    @endif
                </p>
                <a href="{{ route('products') }}" class="btn btn-primary mt-7">{{ t('Browse all products') }}</a>
            </div>
        @else
            <div class="product-grid mt-8">
                @foreach ($products as $product)
                    @include('storefront.partials.product-card', ['product' => $product])
                @endforeach
            </div>
            {{ $products->links('storefront.partials.pagination') }}
        @endif
    </div>
@endsection
