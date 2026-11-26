@if ($announcement)
    <div class="bg-ink text-white">
        <div class="container-store py-2 text-center text-label font-medium">
            @if ($announcement['link'])
                <a href="{{ $announcement['link'] }}" class="underline-offset-4 hover:underline">{{ $announcement['text'] }}</a>
            @else
                {{ $announcement['text'] }}
            @endif
        </div>
    </div>
@endif

<header class="sticky top-0 z-40 border-b border-line bg-surface/95 backdrop-blur">
    <div class="container-store flex h-16 items-center gap-4 lg:h-[72px]">
        <a href="{{ url('/') }}" class="font-heading text-[20px] font-bold tracking-tight">{{ $storeName }}</a>

        @if ($navCategories->isNotEmpty())
            <nav aria-label="Categories" class="ml-4 hidden items-center gap-1 lg:flex">
                @foreach ($navCategories as $category)
                    <a href="{{ url('/c/'.$category->slug) }}" class="nav-link">{{ $category->name }}</a>
                @endforeach
            </nav>
        @endif

        <div class="ml-auto flex items-center gap-1">
            @if (Route::has('search'))
                <form action="{{ route('search') }}" method="get" role="search" class="hidden md:block">
                    <label for="header-search" class="sr-only">Search products</label>
                    <input id="header-search" type="search" name="q" placeholder="Search" class="h-11 w-56 rounded-full bg-soft px-5 placeholder:text-muted focus:outline-2 focus:outline-accent">
                </form>
            @endif

            @if (Route::has('account'))
                <a href="{{ route('account') }}" class="icon-btn" aria-label="Account">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.6-3.6 4.5-5 8-5s6.4 1.4 8 5"/></svg>
                </a>
            @endif

            @if (Route::has('cart'))
                <a href="{{ route('cart') }}" class="icon-btn relative" aria-label="Cart" data-cart-open>
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1.2 11H6.2L5 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
                    <span data-cart-count class="cart-count" @if ($cartCount === 0) hidden @endif>{{ $cartCount }}</span>
                </a>
            @endif

            @if ($navCategories->isNotEmpty() || Route::has('search'))
                <button type="button" class="icon-btn {{ $navCategories->isEmpty() ? 'md:hidden' : 'lg:hidden' }}" data-menu-toggle aria-expanded="false" aria-controls="mobile-menu" aria-label="Menu">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            @endif
        </div>
    </div>

    @if ($navCategories->isNotEmpty() || Route::has('search'))
        <nav id="mobile-menu" data-menu-panel hidden aria-label="Menu" class="border-t border-line lg:hidden">
            <div class="container-store flex flex-col py-3">
                @if (Route::has('search'))
                    <form action="{{ route('search') }}" method="get" role="search" class="mb-2 md:hidden">
                        <label for="menu-search" class="sr-only">Search products</label>
                        <input id="menu-search" type="search" name="q" placeholder="Search products" class="h-12 w-full rounded-full bg-soft px-5 placeholder:text-muted focus:outline-2 focus:outline-accent">
                    </form>
                @endif
                @foreach ($navCategories as $category)
                    <a href="{{ url('/c/'.$category->slug) }}" class="nav-link">{{ $category->name }}</a>
                @endforeach
            </div>
        </nav>
    @endif
</header>
