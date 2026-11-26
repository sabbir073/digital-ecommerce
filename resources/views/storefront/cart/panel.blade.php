@php
    use App\Support\Money;
    /** @var \App\Services\Checkout\CartSummary $summary */
    $buy = $buy ?? false;
@endphp
@if (! empty($error))
    <p class="notice notice-error mb-4" role="alert">{{ $error }}</p>
@elseif (! empty($message))
    <p class="notice mb-4" role="status">{{ $message }}</p>
@endif

@if ($summary->isEmpty())
    <div class="py-12 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-soft text-muted">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1.2 11H6.2L5 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
        </span>
        <p class="mt-4 font-heading text-[20px] font-semibold">Your cart is empty</p>
        <p class="mt-1 text-muted">Add a product to see it here.</p>
        <a href="{{ route('products') }}" class="btn btn-primary mt-6">Browse products</a>
    </div>
@else
    <ul class="divide-y divide-line">
        @foreach ($summary->lines as $line)
            <li class="flex gap-4 py-4">
                <a href="{{ $line->product ? route('product', $line->product->slug) : '#' }}" class="h-[76px] w-[76px] shrink-0 overflow-hidden rounded-xl bg-soft" tabindex="-1" aria-hidden="true">
                    @if ($line->image)
                        <img src="{{ $line->image->sizeUrl(320) }}" alt="" width="76" height="76" class="h-full w-full object-cover" loading="lazy">
                    @endif
                </a>
                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            @if ($line->product)
                                <a href="{{ route('product', $line->product->slug) }}" class="font-medium hover:underline">{{ $line->name }}</a>
                            @else
                                <span class="font-medium">{{ $line->name }}</span>
                            @endif
                            @if ($line->variantLabel)
                                <p class="text-label text-muted">{{ $line->variantLabel }}</p>
                            @endif
                            @if ($line->issue)
                                <p class="mt-1 text-label font-semibold text-danger">{{ $line->issue }}</p>
                            @endif
                        </div>
                        <p class="shrink-0 font-semibold">{{ Money::format($line->lineTotalCents) }}</p>
                    </div>
                    <div class="mt-2.5 flex items-center justify-between gap-3">
                        <form action="{{ route('cart.update', $line->id) }}" method="post" data-cart-form class="qty qty-sm" aria-label="Quantity for {{ $line->name }}">
                            @csrf @method('PATCH')
                            <button type="submit" name="quantity" value="{{ $line->quantity - 1 }}" class="qty-btn" aria-label="Decrease quantity">@include('storefront.partials.icon', ['name' => 'minus', 'size' => 16])</button>
                            <span class="w-9 text-center font-semibold" aria-live="polite">{{ $line->quantity }}</span>
                            <button type="submit" name="quantity" value="{{ $line->quantity + 1 }}" class="qty-btn" aria-label="Increase quantity" @disabled($line->available !== null && $line->quantity >= $line->available)>@include('storefront.partials.icon', ['name' => 'plus', 'size' => 16])</button>
                        </form>
                        <form action="{{ route('cart.remove', $line->id) }}" method="post" data-cart-form>
                            @csrf @method('DELETE')
                            <button type="submit" class="inline-flex min-h-11 items-center px-2 text-label font-medium text-muted underline-offset-4 hover:text-ink hover:underline">Remove<span class="sr-only"> {{ $line->name }}</span></button>
                        </form>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>

    <div class="border-t border-line pt-5">
        @include('storefront.cart.coupon', ['summary' => $summary, 'buy' => false])

        <dl class="mt-5 space-y-2">
            <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd>{{ Money::format($summary->subtotalCents) }}</dd></div>
            @if ($summary->discountCents > 0)
                <div class="flex justify-between"><dt class="text-muted">Discount</dt><dd>-{{ Money::format($summary->discountCents) }}</dd></div>
            @endif
            <div class="flex items-baseline justify-between pt-1"><dt class="font-semibold">Total</dt><dd class="font-heading text-[22px] font-semibold">{{ Money::format($summary->totalCents()) }}</dd></div>
        </dl>
        <p class="mt-1 text-label text-muted">Tax, if any, is added at checkout.</p>

        <a href="{{ route('checkout') }}" class="btn btn-accent mt-5 w-full" @if ($summary->hasIssues()) aria-disabled="true" @endif>Checkout</a>
        @if ($summary->hasIssues())
            <p class="mt-2 text-center text-label text-muted">Fix the items marked above to continue.</p>
        @endif
    </div>
@endif
