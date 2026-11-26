<footer class="mt-16 border-t border-line bg-soft">
    <div class="container-store grid gap-10 py-14 md:grid-cols-[1.4fr_repeat(3,1fr)]">
        <div>
            <p class="font-heading text-[20px] font-bold tracking-tight">{{ $storeName }}</p>
            @if ($footerText)
                <p class="mt-3 max-w-xs text-muted">{{ $footerText }}</p>
            @endif
        </div>

        @foreach ($footerMenus as $menu)
            @if ($menu->items->isNotEmpty())
                <nav aria-label="{{ $menu->name }}">
                    <p class="text-label font-semibold uppercase tracking-wide text-muted">{{ $menu->name }}</p>
                    <ul class="mt-3 space-y-1">
                        @foreach ($menu->items as $item)
                            <li><a href="{{ $item->url }}" class="inline-flex min-h-11 items-center hover:underline">{{ $item->label }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        @endforeach
    </div>
    <div class="border-t border-line">
        <div class="container-store py-5 text-label text-muted">&copy; {{ date('Y') }} {{ $storeName }}. All rights reserved.</div>
    </div>
</footer>
