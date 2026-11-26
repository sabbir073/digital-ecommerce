@extends('storefront.layout')

@php use App\Support\Money; @endphp

@section('title', t('My account'))

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="container-store mt-8 lg:mt-10">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="section-heading">{{ t('My orders') }}</h1>
                <p class="mt-2 text-muted">{{ t('Signed in as :email', ['email' => $user->email]) }}</p>
            </div>
            <form action="{{ route('logout') }}" method="post">
                @csrf
                <button type="submit" class="btn btn-secondary btn-sm">{{ t('Sign out') }}</button>
            </form>
        </div>

        @if ($orders->isEmpty())
            <div class="mt-8 rounded-panel bg-soft px-6 py-14 text-center">
                <h2 class="text-[22px]">{{ t('No orders yet') }}</h2>
                <p class="mt-2 text-muted">{{ t('When you buy something, it shows up here with its download links.') }}</p>
                <a href="{{ route('products') }}" class="btn btn-primary mt-6">{{ t('Browse products') }}</a>
            </div>
        @else
            <ul class="mt-8 space-y-4">
                @foreach ($orders as $order)
                    <li class="rounded-card border border-line p-5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold">Order {{ $order->number }}</p>
                                <p class="text-label text-muted">{{ $order->created_at->format('M j, Y') }} · {{ Money::format($order->total_cents) }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="badge">{{ $order->statusLabel() }}</span>
                                <a href="{{ $order->url() }}" class="inline-flex min-h-11 items-center font-medium text-accent hover:underline">{{ t('View order') }}</a>
                            </div>
                        </div>
                        <ul class="mt-3 divide-y divide-line border-t border-line">
                            @foreach ($order->items as $item)
                                <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <span>
                                        <span class="block font-medium">{{ $item->name }}</span>
                                        <span class="block text-label text-muted">{{ $item->variant_label ? $item->variant_label.' · ' : '' }}Qty {{ $item->quantity }}</span>
                                    </span>
                                    <span class="flex flex-wrap gap-2">
                                        @foreach ($item->downloads as $grant)
                                            @if (! $grant->blockedReason())
                                                <a href="{{ $grant->url() }}" class="chip-link">@include('storefront.partials.icon', ['name' => 'download', 'size' => 16])<span class="ml-1.5">{{ $grant->asset?->filename ?? t('Download') }}</span></a>
                                            @endif
                                        @endforeach
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
            {{ $orders->links('storefront.partials.pagination') }}
        @endif

        <section id="your-data" class="mt-12 max-w-2xl scroll-mt-28 border-t border-line pt-8" aria-labelledby="your-data-heading">
            <h2 id="your-data-heading" class="text-[22px]">{{ t('Your data') }}</h2>
            @if (session('status'))
                <p class="mt-3 rounded-card bg-soft px-4 py-3" role="status">{{ session('status') }}</p>
            @endif
            @if ($deletionRequest)
                <p class="mt-3 text-muted">{{ t('You asked us on :date to delete your account and personal data. We are working on it.', ['date' => $deletionRequest->created_at->format('M j, Y')]) }}</p>
                <form action="{{ route('account.deletion.cancel') }}" method="post" class="mt-4">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-secondary btn-sm">{{ t('Cancel my request') }}</button>
                </form>
            @else
                <p class="mt-3 text-muted">{{ t('You can ask us to delete your account and personal data. Your downloads stop working, and we keep only the order records the law requires, without your name or email.') }}</p>
                <form action="{{ route('account.deletion.request') }}" method="post" class="mt-4 flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="min-w-0 flex-1 basis-60">
                        <label for="deletion-password" class="field-label">{{ t('Your password, to confirm') }}</label>
                        <input id="deletion-password" name="password" type="password" required autocomplete="current-password" class="field" @error('password', 'deletion') aria-invalid="true" aria-describedby="deletion-password-error" @enderror>
                    </div>
                    <button type="submit" class="btn btn-secondary">{{ t('Ask to delete my data') }}</button>
                    @error('password', 'deletion')
                        <p id="deletion-password-error" class="field-error basis-full" role="alert">{{ $message }}</p>
                    @enderror
                </form>
            @endif
        </section>
    </div>
@endsection
