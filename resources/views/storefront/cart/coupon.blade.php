{{-- Coupon code: shown in the cart and at checkout. --}}
@if ($summary->coupon)
    <div class="flex items-center justify-between gap-3 rounded-xl bg-accent-tint px-4 py-2.5 text-accent-tint-text">
        <span class="font-medium">Code {{ $summary->coupon->code }} applied</span>
        <form action="{{ route('cart.coupon.remove') }}" method="post" @unless ($buy) data-cart-form @endunless>
            @csrf @method('DELETE')
            @if ($buy) <input type="hidden" name="buy" value="1"> @endif
            <button type="submit" class="inline-flex min-h-11 items-center px-1 text-label font-semibold underline underline-offset-4">{{ t('Remove') }}</button>
        </form>
    </div>
@else
    @if ($summary->couponProblem)
        <p class="mb-2 text-label font-medium text-danger" role="alert">{{ $summary->couponProblem }}</p>
    @endif
    <form action="{{ route('cart.coupon') }}" method="post" @unless ($buy) data-cart-form @endunless class="flex items-end gap-2">
        @csrf
        @if ($buy) <input type="hidden" name="buy" value="1"> @endif
        <div class="min-w-0 flex-1">
            <label class="field-label" for="coupon-{{ $buy ? 'buy' : 'cart' }}">{{ t('Discount code') }}</label>
            <input class="field" id="coupon-{{ $buy ? 'buy' : 'cart' }}" name="code" autocomplete="off" autocapitalize="characters" maxlength="60" required>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">{{ t('Apply') }}</button>
    </form>
    @error('code') <p class="mt-2 text-label font-medium text-danger" role="alert">{{ $message }}</p> @enderror
@endif
