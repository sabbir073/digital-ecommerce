{{-- The blocks of a selling page. In the builder each block gets a wrapper the canvas script can select, move and drop next to. --}}
@php $builder = $builder ?? false; @endphp
@foreach ($blocks as $block)
    @if ($builder)
        @php
            $html = $__env->make('selling.blocks.'.$block['type'], ['block' => $block, 'd' => $block['data'], 'style' => $block['style']], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path', 'html']))->render();
            $label = \App\Support\Blocks::library($blocksFor ?? 'selling')[$block['type']]['label'];
            $empty = trim(strip_tags($html)) === '' && ! preg_match('/<(img|iframe|video)\b/i', $html);
        @endphp
        <div class="bd-block {{ $block['hidden'] ? 'bd-is-hidden' : '' }}" data-block-id="{{ $block['id'] }}" data-block-type="{{ $block['type'] }}" data-block-label="{{ $label }}" @if ($block['hidden']) data-block-hidden @endif>
            @if ($empty)
                <div class="bd-empty">
                    <strong>{{ $label }}</strong>
                    <span>{{ t('This block has no content yet. Select it and fill it in on the right. Customers do not see empty blocks.') }}</span>
                </div>
            @else
                {!! $html !!}
            @endif
        </div>
    @else
        @include('selling.blocks.'.$block['type'], ['block' => $block, 'd' => $block['data'], 'style' => $block['style']])
    @endif
@endforeach
@if ($builder && count($blocks) === 0)
    <div class="bd-blank">{{ t('Drag a block here from the list on the left to start the page.') }}</div>
@endif
