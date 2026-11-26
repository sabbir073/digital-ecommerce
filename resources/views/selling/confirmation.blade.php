@php use App\Support\Money; @endphp
{{-- Replaces the order block after a successful payment. --}}
<div class="mx-auto max-w-2xl rounded-panel bg-surface p-6 text-ink sm:p-8" data-order-confirmation tabindex="-1">
    <div class="text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-accent-tint text-accent-tint-text">
            @include('storefront.partials.icon', ['name' => 'check', 'size' => 28])
        </span>
        <h2 class="mt-5 text-[28px]">{{ $order->name ? t('Thank you, :name', ['name' => Str::before($order->name, ' ')]) : t('Thank you') }}</h2>
        <p class="mt-2 text-muted">{{ t('Order') }} <span class="font-semibold text-ink">{{ $order->number }}</span> {{ t('is paid.') }}</p>
        <p class="mt-1 text-muted">{{ t('A confirmation email with your download links was sent to :email.', ['email' => $order->email]) }}</p>
    </div>

    @php $grants = $order->items->flatMap->downloads; @endphp
    <h3 class="mt-8 text-[18px]">{{ t('Your downloads') }}</h3>
    @if ($grants->isEmpty())
        <p class="mt-2 text-muted">{{ t('Your files are being prepared. We will email you as soon as they are ready.') }}</p>
    @else
        <ul class="mt-3 divide-y divide-line rounded-card border border-line">
            @foreach ($order->items as $item)
                @foreach ($item->downloads as $grant)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <span class="min-w-0">
                            <span class="block font-medium">{{ $item->name }}{{ $item->variant_label ? ' ('.$item->variant_label.')' : '' }}</span>
                            <span class="block truncate text-label text-muted">{{ $grant->asset?->filename }} · {{ t(':left of :max downloads left', ['left' => $grant->remaining(), 'max' => $grant->max_downloads]) }}</span>
                        </span>
                        <a href="{{ $grant->url() }}" class="btn btn-primary btn-sm">@include('storefront.partials.icon', ['name' => 'download', 'size' => 18]) Download</a>
                    </li>
                @endforeach
            @endforeach
        </ul>
    @endif

    <dl class="mt-6 space-y-1.5 border-t border-line pt-4">
        @if ($order->discount_cents > 0)
            <div class="flex justify-between"><dt class="text-muted">Discount{{ $order->coupon_code ? ' ('.$order->coupon_code.')' : '' }}</dt><dd>-{{ Money::format($order->discount_cents) }}</dd></div>
        @endif
        @if ($order->tax_cents > 0)
            <div class="flex justify-between"><dt class="text-muted">{{ t('Tax') }}</dt><dd>{{ Money::format($order->tax_cents) }}</dd></div>
        @endif
        <div class="flex justify-between font-semibold"><dt>{{ t('Total paid') }}</dt><dd>{{ Money::format($order->total_cents) }}</dd></div>
    </dl>
</div>
