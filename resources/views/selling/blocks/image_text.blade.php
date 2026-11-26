@php $image = $media[$d['image_id']] ?? null; @endphp
@include('selling.blocks._open')
    <div class="container-selling grid items-center gap-10 md:grid-cols-2">
        @if ($image)
            <div class="min-w-0 {{ $d['image_side'] === 'right' ? 'md:order-2' : '' }}">
                <img src="{{ $image->sizeUrl(960) }}" srcset="{{ $image->srcset() }}" sizes="(min-width: 768px) 536px, 100vw" alt="{{ $image->alt ?: $d['heading'] }}"
                     width="{{ $image->width }}" height="{{ $image->height }}" loading="lazy" class="sp-media" @editimage('image_id')>
            </div>
        @endif
        <div class="sp-stack {{ $image ? '' : 'md:col-span-2' }}">
            @if ($d['heading'])
                <h2 class="section-heading" @edit('heading')>{{ $d['heading'] }}</h2>
            @endif
            @if ($d['text'])
                <p class="sp-muted" @edit('text')>{!! nl2br(e($d['text'])) !!}</p>
            @endif
            @if ($d['bullets'])
                <ul class="space-y-2.5 text-left">
                    @foreach ($d['bullets'] as $index => $bullet)
                        <li class="flex gap-2.5">
                            <span class="mt-0.5 shrink-0 text-accent" aria-hidden="true">@include('storefront.partials.icon', ['name' => 'check', 'size' => 20])</span>
                            <span @edit("bullets.$index")>{{ $bullet }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</section>
