<section class="container-store mt-6 lg:mt-10">
    <div class="grid items-center gap-10 rounded-panel-lg bg-soft px-6 py-10 md:px-12 md:py-14 lg:grid-cols-[1.05fr_1fr] lg:gap-14">
        <div class="rise">
            @if (! empty($s['eyebrow']))
                <span class="badge">{{ $s['eyebrow'] }}</span>
            @endif
            <h1 class="page-headline mt-5 max-w-xl">{{ $s['headline'] }}</h1>
            @if (! empty($s['text']))
                <p class="mt-5 max-w-md text-[17px] text-muted">{{ $s['text'] }}</p>
            @endif
            <div class="mt-8 flex flex-wrap gap-3">
                @if (! empty($s['primary_label']))
                    <a href="{{ url($s['primary_url']) }}" class="btn btn-primary">{{ $s['primary_label'] }} @include('storefront.partials.icon', ['name' => 'arrow', 'size' => 18])</a>
                @endif
                @if (! empty($s['secondary_label']))
                    <a href="{{ url($s['secondary_url']) }}" class="btn btn-secondary">{{ $s['secondary_label'] }}</a>
                @endif
            </div>
        </div>

        <div class="hero-tiles rise rise-late" aria-hidden="{{ $data['tiles']->isEmpty() ? 'true' : 'false' }}">
            @for ($i = 0; $i < 3; $i++)
                @php $tile = $data['tiles'][$i] ?? null; @endphp
                <div class="hero-tile hero-tile-{{ $i + 1 }}">
                    @if ($tile)
                        <img src="{{ $tile->sizeUrl(960) }}" srcset="{{ $tile->srcset() }}" sizes="(min-width: 1024px) 320px, 50vw" alt="{{ $tile->alt ?? '' }}"
                             width="960" height="960" @if ($i === 0) fetchpriority="high" @else loading="lazy" @endif decoding="async">
                    @endif
                </div>
            @endfor
        </div>
    </div>
</section>
