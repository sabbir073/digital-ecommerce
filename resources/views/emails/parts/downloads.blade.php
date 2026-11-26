@php
    $grants = $order->items->flatMap->downloads;
    $first = $grants->first();
    $until = $first?->expires_at ? ' until '.$first->expires_at->format('F j, Y') : '';
@endphp
@if ($grants->isNotEmpty())
## Your downloads

@foreach ($order->items as $item)
@foreach ($item->downloads as $grant)
- **{{ $item->name }}**: [{{ $grant->asset?->filename ?? 'Download' }}]({{ $grant->url() }})
@endforeach
@endforeach

Each link works {{ $first->max_downloads }} times{{ $until }}.
@endif
