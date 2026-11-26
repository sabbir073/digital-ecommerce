@if (! empty($s['items']))
    <section class="container-store section-gap" aria-label="{{ t('Why shop with us') }}">
        <ul class="grid gap-px overflow-hidden rounded-panel border border-line bg-line sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($s['items'] as $item)
                <li class="flex gap-4 bg-surface p-6">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-accent-tint text-accent-tint-text">
                        @include('storefront.partials.icon', ['name' => $item['icon'] ?? 'check'])
                    </span>
                    <span>
                        <span class="block font-semibold">{{ $item['title'] }}</span>
                        <span class="mt-1 block text-label text-muted">{{ $item['text'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
