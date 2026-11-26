<x-mail::message>
# Your refund is on its way

Hi {{ $order->name ?: 'there' }}, we refunded **{{ $amount }}** for order **{{ $order->number }}**. The money goes back to the card you paid with. Banks usually show it within 5 to 10 days.

@if ($full)
Because the order was refunded in full, its download links no longer work.
@endif

<x-mail::button :url="$order->url()">
View your order
</x-mail::button>

Thanks,<br>
{{ $storeName }}
</x-mail::message>
