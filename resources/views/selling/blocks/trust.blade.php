@php $items = collect($d['items'])->filter(fn ($i) => $i['title'] !== '' || $i['text'] !== ''); @endphp
@if ($items->isNotEmpty())
    @include('selling.blocks._open')
        <div class="container-selling sp-trust" style="--sp-cols: {{ min(4, $items->count()) }}">
            @foreach ($items as $index => $item)
                <div class="flex gap-3">
                    <span class="mt-0.5 shrink-0 text-accent" aria-hidden="true">@include('storefront.partials.icon', ['name' => 'check', 'size' => 22])</span>
                    <div>
                        <p class="font-semibold" @edit("items.$index.title")>{{ $item['title'] }}</p>
                        @if ($item['text'])
                            <p class="mt-1 text-label sp-muted" @edit("items.$index.text")>{{ $item['text'] }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif
