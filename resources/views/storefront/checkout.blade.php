@extends('storefront.layout')

@php use App\Support\Money; @endphp

@section('title', 'Checkout')

@push('head')
    <meta name="robots" content="noindex">
    @vite('resources/js/storefront/checkout.ts')
@endpush

@section('content')
    <div class="container-store mt-8 lg:mt-10">
        <h1 class="section-heading">Checkout</h1>

        @if ($testMode)
            <p class="notice mt-5" role="status">Test mode: no real money is taken. Use Stripe's test cards.</p>
        @endif
        @unless ($ready)
            <p class="notice notice-error mt-5" role="alert">Payments are not set up yet, so orders cannot be placed. Please check back soon.</p>
        @endunless

        <div class="mt-7 grid gap-10 lg:grid-cols-[1fr_400px] lg:gap-14">
            <form id="checkout-form" novalidate data-checkout class="space-y-10">
                <section aria-labelledby="co-contact">
                    <h2 id="co-contact" class="text-[20px]">Contact</h2>
                    <p class="mt-1 text-muted">Your download links are sent to this email.</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="field-label" for="co-email">Email</label>
                            <input class="field" id="co-email" name="email" type="email" autocomplete="email" inputmode="email" required value="{{ $customer['email'] }}" aria-describedby="err-email">
                            <p class="field-error" id="err-email" data-error="email" role="alert"></p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="field-label" for="co-name">Full name</label>
                            <input class="field" id="co-name" name="name" autocomplete="name" required maxlength="120" value="{{ $customer['name'] }}" aria-describedby="err-name">
                            <p class="field-error" id="err-name" data-error="name" role="alert"></p>
                        </div>
                        <div>
                            <label class="field-label" for="co-country">Country</label>
                            <select class="field" id="co-country" name="country" autocomplete="country" required aria-describedby="err-country">
                                <option value="">Choose a country</option>
                                @foreach ($countries as $code => $name)
                                    <option value="{{ $code }}" @selected($customer['country'] === $code)>{{ $name }}</option>
                                @endforeach
                            </select>
                            <p class="field-error" id="err-country" data-error="country" role="alert"></p>
                        </div>
                        <div>
                            <label class="field-label" for="co-postcode">ZIP or postal code</label>
                            <input class="field" id="co-postcode" name="postcode" autocomplete="postal-code" maxlength="20" aria-describedby="err-postcode">
                            <p class="field-error" id="err-postcode" data-error="postcode" role="alert"></p>
                        </div>
                    </div>

                    @include('storefront.partials.checkout-extras', ['prefix' => 'co'])

                    @guest('web')
                        @if ($checkoutFields['guest_checkout'])
                            <label class="check mt-4">
                                <input type="checkbox" name="create_account" value="1" data-account-toggle>
                                <span>Create an account to see my orders and downloads later</span>
                            </label>
                        @else
                            <p class="mt-4 text-muted">Orders need an account. Choose a password to make one, or <a href="{{ route('login') }}" class="underline">sign in</a>.</p>
                        @endif
                        <div class="mt-3" data-account-fields @if ($checkoutFields['guest_checkout']) hidden @else data-account-required @endif>
                            <label class="field-label" for="co-password">Password</label>
                            <input class="field" id="co-password" name="password" type="password" autocomplete="new-password" minlength="8" aria-describedby="err-password">
                            <p class="field-error" id="err-password" data-error="password" role="alert"></p>
                        </div>
                    @endguest
                </section>

                <section aria-labelledby="co-payment">
                    <h2 id="co-payment" class="text-[20px]">Payment</h2>
                    <p class="mt-1 text-muted">Card details go straight to Stripe and never touch our servers.</p>
                    <div class="mt-4 min-h-24" data-payment-element></div>
                    <p class="field-error mt-3" data-error="checkout" role="alert"></p>
                    <p class="field-error" data-error="stock" role="alert"></p>

                    <button type="submit" class="btn btn-accent btn-pay mt-6 w-full" data-pay @disabled(! $ready)>
                        <span data-pay-label>Pay {{ Money::format($summary->totalCents()) }}</span>
                    </button>
                    <p class="mt-3 text-center text-label text-muted">By paying you agree to our terms. You get instant access after payment.</p>
                </section>
            </form>

            <aside aria-labelledby="co-summary" class="lg:sticky lg:top-24 lg:self-start">
                <div class="rounded-panel bg-soft p-6">
                    <h2 id="co-summary" class="text-[20px]">Order summary</h2>
                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($summary->lines as $line)
                            <li class="flex gap-3 py-3">
                                <span class="relative h-16 w-16 shrink-0 overflow-hidden rounded-xl bg-surface">
                                    @if ($line->image)
                                        <img src="{{ $line->image->sizeUrl(320) }}" alt="" width="64" height="64" class="h-full w-full object-cover">
                                    @endif
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium">{{ $line->name }}</span>
                                    <span class="block text-label text-muted">{{ $line->variantLabel ? $line->variantLabel.' · ' : '' }}Qty {{ $line->quantity }}</span>
                                    @if ($line->issue)
                                        <span class="block text-label font-semibold text-danger">{{ $line->issue }}</span>
                                    @endif
                                </span>
                                <span class="font-medium">{{ Money::format($line->lineTotalCents) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-4 border-t border-line pt-4">
                        @include('storefront.cart.coupon', ['summary' => $summary, 'buy' => $buyNow])
                    </div>

                    <dl class="mt-5 space-y-2">
                        <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd>{{ Money::format($summary->subtotalCents) }}</dd></div>
                        @if ($summary->discountCents > 0)
                            <div class="flex justify-between"><dt class="text-muted">Discount</dt><dd>-{{ Money::format($summary->discountCents) }}</dd></div>
                        @endif
                        <div class="flex justify-between"><dt class="text-muted">Tax</dt><dd data-tax>Calculated from your country</dd></div>
                        <div class="flex items-baseline justify-between border-t border-line pt-3">
                            <dt class="font-semibold">Total</dt>
                            <dd class="font-heading text-[24px] font-semibold" data-total aria-live="polite">{{ Money::format($summary->totalCents()) }}</dd>
                        </div>
                    </dl>
                </div>
                @unless ($buyNow)
                    <a href="{{ route('cart') }}" class="mt-3 inline-flex min-h-11 items-center font-medium text-accent hover:underline">Edit cart</a>
                @endunless
            </aside>
        </div>
    </div>

    <script type="application/json" id="checkout-config">@json($config)</script>
@endsection
