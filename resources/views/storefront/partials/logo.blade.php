@if ($theme['logo'])
    <img src="{{ $theme['logo']['url'] }}" alt="{{ $storeName }}" width="{{ $theme['logo']['width'] }}" height="{{ $theme['logo']['height'] }}" class="h-8 w-auto max-w-[180px] object-contain">
@else
    {{ $storeName }}
@endif
