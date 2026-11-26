{{-- Checkout fields the owner can switch on under Settings, Store: phone, order note, terms. --}}
@php($p = $prefix ?? 'co')
@if ($checkoutFields['phone'] !== 'hidden')
    <div class="mt-4">
        <label class="field-label" for="{{ $p }}-phone">Phone @if ($checkoutFields['phone'] === 'optional')<span class="font-normal text-muted">(optional)</span>@endif</label>
        <input class="field" id="{{ $p }}-phone" name="phone" type="tel" autocomplete="tel" maxlength="40" @required($checkoutFields['phone'] === 'required') aria-describedby="{{ $p }}-err-phone">
        <p class="field-error" id="{{ $p }}-err-phone" data-error="phone" role="alert"></p>
    </div>
@endif
@if ($checkoutFields['order_notes'])
    <div class="mt-4">
        <label class="field-label" for="{{ $p }}-note">Order note <span class="font-normal text-muted">(optional)</span></label>
        <textarea class="field" id="{{ $p }}-note" name="customer_note" rows="2" maxlength="1000"></textarea>
    </div>
@endif
@if ($checkoutFields['terms'])
    <div class="mt-4">
        <label class="check">
            <input type="checkbox" name="terms" value="1" aria-describedby="{{ $p }}-err-terms">
            <span>I accept the @if ($checkoutFields['terms_url'])<a href="{{ $checkoutFields['terms_url'] }}" target="_blank" rel="noopener" class="underline">terms</a>@else terms @endif of this store</span>
        </label>
        <p class="field-error" id="{{ $p }}-err-terms" data-error="terms" role="alert"></p>
    </div>
@endif
