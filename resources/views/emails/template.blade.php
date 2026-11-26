<x-mail::message>
@foreach ($parts as $part)
@switch($part['type'])
@case('text')
{!! $part['text'] !!}
@break
@case('button')
<x-mail::button :url="$part['url']">
{{ $part['label'] ?? 'Open' }}
</x-mail::button>
@break
@case('buttons')
@foreach ($part['buttons'] as $button)
<x-mail::button :url="$button['url']">
{{ $button['label'] }}
</x-mail::button>
@endforeach
@break
@case('panel')
<x-mail::panel>
{{ $part['text'] }}
</x-mail::panel>
@break
@case('view')
@include($part['view'], $part['data'])
@break
@endswitch

@endforeach
@if ($address !== '')
<x-mail::subcopy>
{{ $storeName }} · {{ $address }}
</x-mail::subcopy>
@endif
</x-mail::message>
