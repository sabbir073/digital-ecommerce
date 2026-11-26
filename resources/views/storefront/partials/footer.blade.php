<footer class="mt-16 border-t border-line bg-soft">
    <div class="container-store grid gap-10 py-14 md:grid-cols-[1.4fr_repeat(3,1fr)]">
        <div>
            <p class="font-heading text-[20px] font-bold tracking-tight">{{ $storeName }}</p>
            @if ($footerText)
                <p class="mt-3 max-w-xs text-muted">{{ $footerText }}</p>
            @endif
            @if ($storeContact)
                <address class="mt-3 max-w-xs not-italic text-muted">
                    @isset($storeContact['address'])<span class="block whitespace-pre-line">{{ $storeContact['address'] }}</span>@endisset
                    @isset($storeContact['email'])<a href="mailto:{{ $storeContact['email'] }}" class="block hover:underline">{{ $storeContact['email'] }}</a>@endisset
                    @isset($storeContact['phone'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $storeContact['phone']) }}" class="block hover:underline">{{ $storeContact['phone'] }}</a>@endisset
                </address>
            @endif
            @if ($theme['socials'])
                <ul class="mt-4 flex flex-wrap gap-x-4" aria-label="Social links">
                    @foreach ($theme['socials'] as $social)
                        <li><a href="{{ $social['url'] }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center font-medium hover:underline">{{ $social['label'] }}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>

        @foreach ($footerMenus as $menu)
            <nav aria-label="{{ $menu['name'] }}">
                <p class="text-label font-semibold uppercase tracking-wide text-muted">{{ $menu['name'] }}</p>
                <ul class="mt-3 space-y-1">
                    @foreach ($menu['items'] as $item)
                        <li><a href="{{ $item['url'] }}" class="inline-flex min-h-11 items-center hover:underline">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endforeach
    </div>
    <div class="border-t border-line">
        <div class="container-store py-5 text-label text-muted">&copy; {{ date('Y') }} {{ $storeName }}. All rights reserved.</div>
    </div>
</footer>
