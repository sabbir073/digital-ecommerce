{{-- Shared wrapper: per-block style is limited to the theme (background, spacing, alignment). --}}
<section @isset($id) id="{{ $id }}" @endisset data-block="{{ $block['type'] }}" class="sp-section sp-bg-{{ $style['background'] }} sp-pad-{{ $style['spacing'] }} {{ $style['align'] === 'center' ? 'sp-center' : '' }} {{ $class ?? '' }}">
