@php $images = collect($d['image_ids'])->map(fn ($id) => $media[$id] ?? null)->filter()->values(); @endphp
@if ($images->count() >= 2)
    @include('selling.blocks._open')
        <div class="container-selling">
            <ul class="{{ $d['layout'] === 'slider' ? 'sp-slider' : 'sp-gallery' }}" @if ($d['layout'] === 'slider') tabindex="0" aria-label="Photo gallery, scroll sideways" @endif>
                @foreach ($images as $image)
                    <li>
                        <img src="{{ $image->sizeUrl(640) }}" srcset="{{ $image->srcset() }}" sizes="(min-width: 1024px) 350px, (min-width: 640px) 50vw, 80vw" alt="{{ $image->alt ?: '' }}"
                             width="{{ $image->width }}" height="{{ $image->height }}" loading="lazy" class="sp-media sp-media-square">
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif
