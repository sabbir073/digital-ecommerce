@php $items = collect($d['items'])->filter(fn ($i) => $i['title'] !== '' || $i['text'] !== ''); @endphp
@if ($items->isNotEmpty())
    @include('selling.blocks._open')
        <div class="container-selling sp-cards" style="--sp-cols: {{ min(4, $items->count()) }}">
            @foreach ($items as $index => $item)
                <div class="sp-card">
                    <h3 class="text-[19px]" @edit("items.$index.title")>{{ $item['title'] }}</h3>
                    @if ($item['text'])
                        <p class="mt-2 sp-muted" @edit("items.$index.text")>{{ $item['text'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
