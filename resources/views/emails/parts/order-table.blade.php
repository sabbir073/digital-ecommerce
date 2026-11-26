@php use App\Support\Money; @endphp
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
