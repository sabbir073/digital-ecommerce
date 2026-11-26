@if ($d['html'] !== '')
    @include('selling.blocks._open')
        <div class="container-selling">
            {{-- Cleaned by HtmlSanitizer when saved and again when read: only basic text tags survive. --}}
            <div class="sp-prose {{ $style['align'] === 'center' ? 'mx-auto' : '' }}">{!! App\Support\HtmlSanitizer::clean($d['html']) !!}</div>
        </div>
    </section>
@endif
