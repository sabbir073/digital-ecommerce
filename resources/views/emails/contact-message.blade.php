<x-mail::message>
# New message from the contact form

**{{ $contactMessage->name }}** ({{ $contactMessage->email }}) wrote:

<x-mail::panel>
{{ $contactMessage->message }}
</x-mail::panel>

Reply to this email to answer them. The message is also in your admin inbox.

<x-mail::button :url="url('/admin/messages')">
Open messages
</x-mail::button>

{{ $storeName }}
</x-mail::message>
