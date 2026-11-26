{{-- Checkout fields the owner can switch on under Settings, Store: phone, order note, terms. --}}
@php($p = $prefix ?? 'co')
@if ($checkoutFields['phone'] !== 'hidden')
    <div class="mt-4">
        <label class="field-label" for="{{ $p }}-phone">Phone @if ($checkoutFields['phone'] === 'optional')<span class="font-normal text-muted">{{ t('(optional)') }}</span>@endif</label>
        <input class="field" id="{{ $p }}-phone" name="phone" type="tel" autocomplete="tel" maxlength="40" @required($checkoutFields['phone'] === 'required') aria-describedby="{{ $p }}-err-phone">
        <p class="field-error" id="{{ $p }}-err-phone" data-error="phone" role="alert"></p>
    </div>
@endif
@if ($checkoutFields['order_notes'])
    <div class="mt-4">
        <label class="field-label" for="{{ $p }}-note">{{ t('Order note') }} <span class="font-normal text-muted">{{ t('(optional)') }}</span></label>
        <textarea class="field" id="{{ $p }}-note" name="customer_note" rows="2" maxlength="1000"></textarea>
    </div>
@endif
@if ($checkoutFields['terms'])
    <div class="mt-4">
        <label class="check">
            <input type="checkbox" name="terms" value="1" aria-describedby="{{ $p }}-err-terms">
            <span>@if ($checkoutFields['terms_url']){{ t('I accept the') }} <a href="{{ $checkoutFields['terms_url'] }}" target="_blank" rel="noopener" class="underline">{{ t('terms of this store') }}</a>@else{{ t('I accept the terms of this store') }}@endif</span>
        </label>
        <p class="field-error" id="{{ $p }}-err-terms" data-error="terms" role="alert"></p>
    </div>
@endif
