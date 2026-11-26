@php
    // Thin line icons (PRD section 8). $name picks the path, $size the pixel size.
    $paths = [
        'download' => '<path d="M12 4v11"/><path d="m7.5 11 4.5 4.5 4.5-4.5"/><path d="M5 19.5h14"/>',
        'lock' => '<rect x="5" y="10.5" width="14" height="9.5" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>',
        'refresh' => '<path d="M4.5 12a7.5 7.5 0 0 1 12.8-5.3L19.5 9"/><path d="M19.5 4.5V9H15"/><path d="M19.5 12a7.5 7.5 0 0 1-12.8 5.3L4.5 15"/><path d="M4.5 19.5V15H9"/>',
        'chat' => '<path d="M5 6.5A2.5 2.5 0 0 1 7.5 4h9A2.5 2.5 0 0 1 19 6.5v6a2.5 2.5 0 0 1-2.5 2.5H11l-4.5 4v-4A2.5 2.5 0 0 1 5 12.5v-6Z"/>',
        'arrow' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'minus' => '<path d="M6 12h12"/>',
        'plus' => '<path d="M12 6v12M6 12h12"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/>',
        'star' => '<path d="m12 4 2.5 5.1 5.5.8-4 3.9.9 5.6-4.9-2.6-4.9 2.6.9-5.6-4-3.9 5.5-.8L12 4Z"/>',
    ];
    $size = $size ?? 22;
@endphp
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" @isset($class) class="{{ $class }}" @endisset>{!! $paths[$name] ?? '' !!}</svg>
