<x-mail::message>
# New chat message

**{{ $conversation->displayName() }}**@if ($conversation->email) ({{ $conversation->email }})@endif wrote in the live chat while nobody was at the inbox:

<x-mail::panel>
{{ $text }}
</x-mail::panel>

<x-mail::button :url="url('/admin/chat?open='.$conversation->id)">
Open the conversation
</x-mail::button>

{{ $storeName }}
</x-mail::message>
