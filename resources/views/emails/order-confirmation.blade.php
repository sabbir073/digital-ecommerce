@php use App\Support\Money; $grants = $order->items->flatMap->downloads; @endphp
<x-mail::message>
# Thank you for your order

Hi {{ $order->name ?: 'there' }}, your payment went through. Order number: **{{ $order->number }}**.

@if ($grants->isNotEmpty())
## Your downloads

@foreach ($order->items as $item)
@foreach ($item->downloads as $grant)
- **{{ $item->name }}**: [{{ $grant->asset?->filename ?? 'Download' }}]({{ $grant->url() }})
@endforeach
@endforeach

Each link works {{ $grants->first()->max_downloads }} times@if ($grants->first()->expires_at) until {{ $grants->first()->expires_at->format('F j, Y') }}@endif.
@endif

<x-mail::table>
| Item | Qty | Total |
|:-----|:---:|------:|
@foreach ($order->items as $item)
| {{ $item->name }}{{ $item->variant_label ? ' ('.$item->variant_label.')' : '' }} | {{ $item->quantity }} | {{ Money::format($item->line_total_cents) }} |
@endforeach
@if ($order->discount_cents > 0)
| Discount | | -{{ Money::format($order->discount_cents) }} |
@endif
@if ($order->tax_cents > 0)
| Tax | | {{ Money::format($order->tax_cents) }} |
@endif
| **Total** | | **{{ Money::format($order->total_cents) }}** |
</x-mail::table>

<x-mail::button :url="$order->url()">
View your order
</x-mail::button>

Thanks,<br>
{{ $storeName }}
</x-mail::message>
