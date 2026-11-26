{{-- Dark panel linking to one selling page chosen by the admin. Hidden until a published page is chosen. --}}
@php $page = $data['page'] ?? null; $image = $page?->product?->images->first(); @endphp
@if ($page)
    <section class="container-store section-gap" aria-labelledby="featured-offer-title">
        <div class="grid items-center gap-8 overflow-hidden rounded-panel-lg bg-ink p-8 text-white md:grid-cols-[1.1fr_1fr] md:p-12">
            <div class="flex flex-col items-start gap-4">
                @if ($s['eyebrow'])
                    <span class="rounded-full bg-white/10 px-3.5 py-1.5 text-label font-semibold">{{ $s['eyebrow'] }}</span>
                @endif
                <h2 id="featured-offer-title" class="section-heading">{{ $s['headline'] ?: $page->name }}</h2>
                @if ($s['text'] ?: $page->product?->short_description)
                    <p class="max-w-md text-[#C4C8D0]">{{ $s['text'] ?: $page->product->short_description }}</p>
                @endif
                <a href="{{ $page->url() }}" class="btn btn-accent mt-2">{{ $s['button_label'] ?: 'See the offer' }}</a>
            </div>
            @if ($image)
                <img src="{{ $image->sizeUrl(960) }}" srcset="{{ $image->srcset() }}" sizes="(min-width: 768px) 520px, 100vw" alt="{{ $image->alt ?: $page->product->name }}"
                     width="{{ $image->width }}" height="{{ $image->height }}" loading="lazy" class="aspect-[4/3] w-full rounded-panel object-cover">
            @endif
        </div>
    </section>
@endif
