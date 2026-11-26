@extends('storefront.layout')

@php
    use App\Models\Order;
    use App\Support\Money;
    $waiting = in_array($order->status, [Order::PENDING, Order::FAILED], true);
@endphp

@section('title', 'Order '.$order->number)

@push('head')
    <meta name="robots" content="noindex">
    @if ($waiting)
        @vite('resources/js/storefront/order.ts')
    @endif
@endpush

@section('content')
    <div class="container-store mt-8 lg:mt-12">
        <div class="mx-auto max-w-2xl">
            @if ($waiting)
                {{-- The order turns Paid only when Stripe's signed confirmation arrives; this page waits for it. --}}
                <div class="rounded-panel bg-soft px-6 py-10 text-center" data-order-wait data-status-url="{{ $statusUrl }}">
                    <span class="spinner mx-auto" aria-hidden="true"></span>
                    <h1 class="mt-5 text-[26px]" data-wait-title>Confirming your payment</h1>
                    <p class="mt-2 text-muted" data-wait-text aria-live="polite">This usually takes a few seconds. Please keep this page open.</p>
                    <a href="{{ route('checkout') }}" class="btn btn-primary mt-6" data-wait-retry hidden>Try again</a>
                </div>
            @elseif ($order->status === Order::CANCELLED)
                <div class="rounded-panel bg-soft px-6 py-10 text-center">
                    <h1 class="text-[26px]">This order was cancelled</h1>
                    <p class="mt-2 text-muted">It was not paid, and nothing was charged.</p>
                    <a href="{{ route('products') }}" class="btn btn-primary mt-6">Browse products</a>
                </div>
            @else
                <div class="text-center">
                    <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-accent-tint text-accent-tint-text">
                        @include('storefront.partials.icon', ['name' => 'check', 'size' => 28])
                    </span>
                    <h1 class="mt-5 text-[30px] font-bold">Thank you{{ $order->name ? ', '.Str::before($order->name, ' ') : '' }}</h1>
                    <p class="mt-2 text-muted">Order <span class="font-semibold text-ink">{{ $order->number }}</span> · {{ $order->statusLabel() }}</p>
                    <p class="mt-1 text-muted">A confirmation email with your download links was sent to {{ $order->email }}.</p>
                </div>
            @endif

            @if ($order->wasPaid())
                @php $grants = $order->items->flatMap->downloads; @endphp
                <section class="mt-10" aria-labelledby="order-downloads">
                    <h2 id="order-downloads" class="text-[20px]">Your downloads</h2>
                    @if ($grants->isEmpty())
                        <p class="mt-3 text-muted">Your files are being prepared. We will email you as soon as they are ready.</p>
                    @else
                        <ul class="mt-4 divide-y divide-line rounded-card border border-line">
                            @foreach ($order->items as $item)
                                @foreach ($item->downloads as $grant)
                                    <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                                        <span class="min-w-0">
                                            <span class="block font-medium">{{ $item->name }}</span>
                                            <span class="block truncate text-label text-muted">
                                                {{ $grant->asset?->filename }}@if ($grant->asset?->version) · v{{ $grant->asset->version }}@endif
                                                · {{ $grant->remaining() }} of {{ $grant->max_downloads }} downloads left
                                            </span>
                                        </span>
                                        @if ($grant->blockedReason())
                                            <span class="text-label text-muted">{{ $grant->blockedReason() }}</span>
                                        @else
                                            <a href="{{ $grant->url() }}" class="btn btn-primary btn-sm">@include('storefront.partials.icon', ['name' => 'download', 'size' => 18]) Download</a>
                                        @endif
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            <section class="mt-10" aria-labelledby="order-items">
                <h2 id="order-items" class="text-[20px]">Order details</h2>
                <ul class="mt-4 divide-y divide-line border-y border-line">
                    @foreach ($order->items as $item)
                        <li class="flex justify-between gap-4 py-3">
                            <span>
                                <span class="block font-medium">{{ $item->name }}</span>
                                <span class="block text-label text-muted">{{ $item->variant_label ? $item->variant_label.' · ' : '' }}Qty {{ $item->quantity }}</span>
                            </span>
                            <span class="font-medium">{{ Money::format($item->line_total_cents) }}</span>
                        </li>
                    @endforeach
                </ul>
                <dl class="mt-4 space-y-2">
                    <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd>{{ Money::format($order->subtotal_cents) }}</dd></div>
                    @if ($order->discount_cents > 0)
                        <div class="flex justify-between"><dt class="text-muted">Discount{{ $order->coupon_code ? ' ('.$order->coupon_code.')' : '' }}</dt><dd>-{{ Money::format($order->discount_cents) }}</dd></div>
                    @endif
                    @if ($order->tax_cents > 0)
                        <div class="flex justify-between"><dt class="text-muted">Tax</dt><dd>{{ Money::format($order->tax_cents) }}</dd></div>
                    @endif
                    <div class="flex items-baseline justify-between border-t border-line pt-3"><dt class="font-semibold">Total</dt><dd class="font-heading text-[22px] font-semibold">{{ Money::format($order->total_cents) }}</dd></div>
                    @if ($order->refunded_cents > 0)
                        <div class="flex justify-between"><dt class="text-muted">Refunded</dt><dd>-{{ Money::format($order->refunded_cents) }}</dd></div>
                    @endif
                </dl>
                @if ($order->payment?->card_last4)
                    <p class="mt-3 text-label text-muted">Paid with {{ ucfirst((string) $order->payment->card_brand) }} ending {{ $order->payment->card_last4 }}.</p>
                @endif
            </section>

            <p class="mt-10 text-center"><a href="{{ route('products') }}" class="font-medium text-accent hover:underline">Continue shopping</a></p>
        </div>
    </div>
@endsection
