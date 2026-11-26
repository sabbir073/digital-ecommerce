<x-mail::message>
# We replied to your message

Hi {{ $conversation->name ?: 'there' }}, you wrote to us in the chat on our site. Here is our reply:

<x-mail::panel>
{{ $text }}
</x-mail::panel>

You can answer by replying in the chat on our site.

<x-mail::button :url="url('/')">
Back to {{ $storeName }}
</x-mail::button>

Thanks,<br>
{{ $storeName }}
</x-mail::message>
