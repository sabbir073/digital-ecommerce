@if ($d['text'] !== '' || $d['ends_at'])
    <section data-block="offer_bar" class="sp-offerbar sp-bg-{{ $style['background'] }}" @if ($d['ends_at']) data-offer-bar @endif>
        <div class="container-selling flex flex-wrap items-center justify-center gap-x-4 gap-y-1 py-3 text-center">
            @if ($d['text'] !== '')
                <p class="font-medium" @edit('text')>{{ $d['text'] }}</p>
            @endif
            @if ($d['ends_at'])
                {{-- A real end date set in admin. When it passes the bar disappears and never restarts. --}}
                <p class="font-heading font-semibold tabular-nums" data-countdown="{{ $d['ends_at'] }}" role="timer" aria-live="off">
                    Ends {{ \Illuminate\Support\Carbon::parse($d['ends_at'])->format('M j, Y') }}
                </p>
            @endif
        </div>
    </section>
@endif
