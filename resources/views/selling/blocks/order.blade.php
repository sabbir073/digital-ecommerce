@php use App\Support\Money; @endphp
@include('selling.blocks._open', ['id' => 'order'])
    <div class="container-selling text-left" data-order-root>
        <h2 class="section-heading {{ $style['align'] === 'center' ? 'text-center' : '' }}" @edit('heading')>{{ $d['heading'] ?: 'Complete your order' }}</h2>
        @if ($d['subline'])
            <p class="mt-2 sp-muted {{ $style['align'] === 'center' ? 'text-center' : '' }}" @edit('subline')>{{ $d['subline'] }}</p>
        @endif

        @if ($testMode)
            <p class="notice mt-5" role="status">Test mode: no real money is taken. Use Stripe's test cards.</p>
        @endif

        @if (! $offers)
            <p class="notice notice-error mt-6" role="alert">This offer is not available right now.</p>
        @else
            <form novalidate class="sp-order mt-7" data-order-form>
                <div class="min-w-0 space-y-3">
                    <fieldset>
                        <legend class="font-semibold">1. Choose your pack</legend>
                        <div class="mt-3 space-y-3">
                            @foreach ($offers as $offer)
                                <label class="sp-pack {{ $offer['soldOut'] ? 'is-sold-out' : '' }}">
                                    <input type="radio" name="offer_id" value="{{ $offer['id'] }}" class="sr-only" data-price="{{ $offer['price'] }}" data-label="{{ $offer['label'] }}"
                                           @checked($offer['default']) @disabled($offer['soldOut'])>
                                    <span class="min-w-0">
                                        <span class="block font-semibold">{{ $offer['label'] }}</span>
                                        @if ($offer['soldOut'])
                                            <span class="block text-label font-semibold text-danger">Sold out</span>
                                        @elseif ($offer['note'])
                                            <span class="block text-label text-muted">{{ $offer['note'] }}</span>
                                        @endif
                                        @if (count($offer['items']) > 1 || ($offer['items'][0] ?? '') !== $product->name)
                                            <span class="mt-1 block text-label text-muted">{{ implode(' + ', $offer['items']) }}</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 text-[18px] font-semibold">{{ Money::format($offer['price']) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    @if ($summary)
                        <div class="rounded-card bg-surface p-[18px] text-ink">
                            <dl class="space-y-2" aria-live="polite">
                                <div class="flex justify-between gap-3"><dt class="text-muted" data-sum-label>{{ collect($offers)->firstWhere('default', true)['label'] ?? '' }}</dt><dd data-sum-subtotal>{{ Money::format($summary->subtotalCents) }}</dd></div>
                                <div class="flex justify-between gap-3" data-sum-discount-row @if ($summary->discountCents === 0) hidden @endif><dt class="text-muted">Discount <span data-sum-code>{{ $summary->coupon ? '('.$summary->coupon->code.')' : '' }}</span></dt><dd data-sum-discount>-{{ Money::format($summary->discountCents) }}</dd></div>
                                <div class="flex justify-between gap-3" data-sum-tax-row hidden><dt class="text-muted">Tax</dt><dd data-sum-tax></dd></div>
                                <div class="flex items-baseline justify-between gap-3 border-t border-line pt-2.5 text-[18px] font-semibold"><dt>Total</dt><dd data-sum-total>{{ Money::format($summary->totalCents()) }}</dd></div>
                            </dl>

                            <div class="mt-4 border-t border-line pt-4" data-coupon>
                                <div class="flex items-center justify-between gap-3 rounded-xl bg-accent-tint px-4 py-2 text-accent-tint-text" data-coupon-applied @unless ($summary->coupon) hidden @endunless>
                                    <span class="font-medium">Code <span data-coupon-code>{{ $summary->coupon?->code }}</span> applied</span>
                                    <button type="button" class="inline-flex min-h-11 items-center px-1 text-label font-semibold underline underline-offset-4" data-coupon-remove>Remove</button>
                                </div>
                                <div class="flex items-end gap-2" data-coupon-entry @if ($summary->coupon) hidden @endif>
                                    <div class="min-w-0 flex-1">
                                        <label class="field-label" for="sp-coupon">Discount code</label>
                                        <input class="field" id="sp-coupon" autocomplete="off" autocapitalize="characters" maxlength="60" data-coupon-input aria-describedby="sp-err-coupon">
                                    </div>
                                    <button type="button" class="btn btn-secondary btn-sm" data-coupon-apply>Apply</button>
                                </div>
                                <p class="field-error" id="sp-err-coupon" data-error="coupon" role="alert">{{ $summary->couponProblem }}</p>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="min-w-0 rounded-panel bg-surface p-6 text-ink">
                    <p class="font-semibold">2. Your details</p>
                    <p class="mt-1 text-label text-muted">Your download links are sent to this email.</p>
                    <div class="mt-3 grid gap-3.5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="field-label" for="sp-email">Email</label>
                            <input class="field" id="sp-email" name="email" type="email" autocomplete="email" inputmode="email" required aria-describedby="sp-err-email">
                            <p class="field-error" id="sp-err-email" data-error="email" role="alert"></p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="field-label" for="sp-name">Full name</label>
                            <input class="field" id="sp-name" name="name" autocomplete="name" required maxlength="120" aria-describedby="sp-err-name">
                            <p class="field-error" id="sp-err-name" data-error="name" role="alert"></p>
                        </div>
                        <div>
                            <label class="field-label" for="sp-country">Country</label>
                            <select class="field" id="sp-country" name="country" autocomplete="country" required aria-describedby="sp-err-country">
                                <option value="">Choose a country</option>
                                @foreach ($countries as $code => $name)
                                    <option value="{{ $code }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <p class="field-error" id="sp-err-country" data-error="country" role="alert"></p>
                        </div>
                        <div>
                            <label class="field-label" for="sp-postcode">ZIP or postal code</label>
                            <input class="field" id="sp-postcode" name="postcode" autocomplete="postal-code" maxlength="20" aria-describedby="sp-err-postcode">
                            <p class="field-error" id="sp-err-postcode" data-error="postcode" role="alert"></p>
                        </div>
                    </div>

                    <p class="mt-6 font-semibold">3. Payment</p>
                    <p class="mt-1 text-label text-muted">Card details go straight to Stripe and never touch our servers.</p>
                    @unless ($ready)
                        <p class="notice notice-error mt-3" role="alert">Payments are not set up yet, so orders cannot be placed. Please check back soon.</p>
                    @endunless
                    <div class="mt-3 min-h-24" data-payment-element></div>

                    <p class="field-error mt-3" data-error="checkout" role="alert"></p>
                    <p class="field-error" data-error="stock" role="alert"></p>

                    <button type="submit" class="btn btn-accent btn-pay mt-4 w-full text-[17px]" data-pay @disabled(! $ready || ! $summary || $preview)>
                        <span data-pay-label>{{ $d['pay_label'] ?: 'Pay' }} {{ $summary ? Money::format($summary->totalCents()) : '' }}</span>
                    </button>
                    @if ($preview)
                        <p class="mt-3 text-center text-label text-muted">Paying is switched off in the preview.</p>
                    @else
                        <p class="mt-3 text-center text-label text-muted">Encrypted and processed by Stripe. You get instant access after payment.</p>
                    @endif
                </div>
            </form>
        @endif
    </div>
</section>
