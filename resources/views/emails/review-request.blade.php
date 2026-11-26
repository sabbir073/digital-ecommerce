<x-mail::message>
# How was your order?

Hi {{ $order->name ?: 'there' }}, thank you for buying from {{ $storeName }}. Would you tell other customers what you think? It takes about a minute and you do not need to sign in.

@foreach ($requests as $request)
<x-mail::button :url="$request->url()">
Review {{ $request->orderItem->name }}
</x-mail::button>
@endforeach

Your review is marked as written by a verified buyer of order {{ $order->number }}.

Thanks,<br>
{{ $storeName }}
</x-mail::message>
