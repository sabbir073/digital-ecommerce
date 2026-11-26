{{-- Country flag as a bundled SVG image (never an emoji), with the country name as alt text and tooltip. --}}
@php
    $flagCode = strtoupper((string) ($code ?? ''));
    $flagName = config('countries')[$flagCode] ?? null;
@endphp
@if ($flagName && app(\App\Services\Reviews\ReviewSettings::class)->get('show_flags'))
    <img src="{{ asset('flags/'.strtolower($flagCode).'.svg') }}" alt="{{ $flagName }}" title="{{ $flagName }}" width="20" height="15" loading="lazy" class="flag">
@endif
