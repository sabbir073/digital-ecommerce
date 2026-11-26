@php
    use App\Support\Money;
    use App\Support\VideoEmbed;
    $image = $media[$d['image_id']] ?? null;
    $video = VideoEmbed::resolve($d['video_url']);
    // A content page has no product, so no price and no order button.
    $product = $product ?? null;
    $offer = collect($offers ?? [])->firstWhere('default', true) ?? ($offers[0] ?? null);
    $price = $offer['price'] ?? $product?->price_cents;
    $compare = $product?->compare_at_cents;
    $heroTitle = $d['headline'] ?: ($product?->name ?? '');
    $hasOrder = $hasOrder ?? false;
@endphp
@include('selling.blocks._open')
    <div class="container-selling grid items-center gap-10 md:grid-cols-2" data-hero>
        <div class="sp-stack {{ $d['image_side'] === 'left' ? 'md:order-2' : '' }}">
            @if ($d['badge'])
                <span class="badge" @edit('badge')>{{ $d['badge'] }}</span>
            @endif
            @if ($heroTitle !== '' || ($builder ?? false))
                <h1 class="sp-headline" @edit('headline')>{{ $heroTitle }}</h1>
            @endif
            @if ($d['subline'])
                <p class="sp-lead" @edit('subline')>{{ $d['subline'] }}</p>
            @endif
            @if (($d['show_price'] ?? false) && $product)
                <p class="flex items-baseline gap-3">
                    <span class="text-[32px] font-semibold">{{ Money::format($price) }}</span>
                    @if ($compare && $compare > $price)
                        <s class="text-[18px] sp-muted">{{ Money::format($compare) }}</s>
                    @endif
                </p>
            @endif
            @if ($hasOrder)
                <a href="#order" class="btn btn-accent btn-pay px-9 text-[17px]" data-order-link @edit('button_label')>{{ $d['button_label'] ?: 'Order now' }}</a>
            @elseif (! $product && $d['button_label'] && ($d['button_url'] ?? ''))
                <a href="{{ $d['button_url'] }}" class="btn btn-accent px-9 text-[17px]" @edit('button_label')>{{ $d['button_label'] }}</a>
            @endif
            @if ($d['note'])
                <p class="text-label sp-muted" @edit('note')>{{ $d['note'] }}</p>
            @endif
        </div>
        <div class="min-w-0">
            @if ($video)
                @include('selling.blocks._player', ['video' => $video, 'poster' => $image, 'title' => $d['headline'], 'eager' => true])
            @elseif ($image)
                <img src="{{ $image->sizeUrl(960) }}" srcset="{{ $image->srcset() }}" sizes="(min-width: 768px) 536px, 100vw" alt="{{ $image->alt ?: $heroTitle }}"
                     width="{{ $image->width }}" height="{{ $image->height }}" fetchpriority="high" class="sp-media" @editimage('image_id')>
            @endif
        </div>
    </div>
</section>
