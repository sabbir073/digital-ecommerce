@php $items = collect($d['items'])->filter(fn ($i) => $i['q'] !== ''); @endphp
@if ($items->isNotEmpty())
    @include('selling.blocks._open')
        <div class="container-selling">
            <div class="mx-auto max-w-[712px] text-left">
                @if ($d['heading'])
                    <h2 class="text-[28px] {{ $style['align'] === 'center' ? 'text-center' : '' }}" @edit('heading')>{{ $d['heading'] }}</h2>
                @endif
                <div class="mt-4 border-t sp-line">
                    @foreach ($items as $index => $item)
                        <details class="sp-faq" @if ($index === 0) open @endif>
                            <summary>
                                <span @edit("items.$index.q")>{{ $item['q'] }}</span>
                                <span class="sp-faq-icon" aria-hidden="true">@include('storefront.partials.icon', ['name' => 'chevron', 'size' => 20])</span>
                            </summary>
                            <p class="mt-2 sp-muted" @edit("items.$index.a")>{!! nl2br(e($item['a'])) !!}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endif
