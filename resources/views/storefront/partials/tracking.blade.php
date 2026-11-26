{{--
    The tracking layer and the cookie banner (PRD section 14). Nothing is printed when no service is switched on,
    in the page builder, or in a preview. Tags themselves are loaded by tracking.ts, after the page is usable and
    only for the categories the visitor allowed.
--}}
@php
    $tracking = ($builder ?? false) || ($preview ?? false)
        ? null
        : app(\App\Services\Integrations\PageTracking::class)->for(request(), $trackEvents ?? [], $trackPage ?? null);
@endphp
@if ($tracking)
    <script type="application/json" id="tracking-config">{!! json_encode($tracking['config'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @if ($banner = $tracking['banner'])
        <section id="cookie-banner" hidden role="dialog" aria-modal="false" aria-labelledby="cookie-banner-title"
            class="fixed inset-x-0 bottom-0 z-[70] border-t border-line bg-surface shadow-[0_-8px_30px_rgba(0,0,0,0.12)] sm:inset-x-auto sm:bottom-5 sm:left-5 sm:max-w-[440px] sm:rounded-2xl sm:border">
            <div class="max-h-[80vh] overflow-y-auto p-5">
                <h2 id="cookie-banner-title" class="text-[17px] font-semibold text-ink">{{ $banner['title'] }}</h2>
                <p class="mt-2 text-[14px] leading-relaxed text-muted">
                    {{ $banner['text'] }}
                    @if ($banner['policy'])
                        <a href="{{ $banner['policy']['url'] }}" class="underline">{{ $banner['policy']['title'] }}</a>
                    @endif
                </p>
                <div data-consent-options hidden class="mt-4 space-y-3 border-t border-line pt-4">
                    @foreach ($banner['categories'] as $key => $category)
                        <label class="flex items-start gap-3 text-[14px]">
                            <input type="checkbox" name="{{ $key }}" class="mt-1 size-4 shrink-0" @if ($key === 'necessary') checked disabled @endif>
                            <span><span class="font-semibold text-ink">{{ $category['label'] }}</span><span class="block text-muted">{{ $category['text'] }}</span></span>
                        </label>
                    @endforeach
                </div>
                <div class="mt-5 flex flex-wrap gap-2">
                    <button type="button" class="btn btn-primary btn-sm" data-consent="accept">{{ $banner['accept'] }}</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-consent="reject">{{ $banner['reject'] }}</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-consent="choose">{{ $banner['choose'] }}</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-consent="save" hidden>{{ $banner['save'] }}</button>
                </div>
            </div>
        </section>
    @endif
    @vite('resources/js/storefront/tracking.ts')
@endif
