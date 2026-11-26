@extends('storefront.layout')

@php use App\Support\Money; @endphp

@section('title', 'My account')

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="container-store mt-8 lg:mt-10">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="section-heading">My orders</h1>
                <p class="mt-2 text-muted">Signed in as {{ $user->email }}</p>
            </div>
            <form action="{{ route('logout') }}" method="post">
                @csrf
                <button type="submit" class="btn btn-secondary btn-sm">Sign out</button>
            </form>
        </div>

        @if ($orders->isEmpty())
            <div class="mt-8 rounded-panel bg-soft px-6 py-14 text-center">
                <h2 class="text-[22px]">No orders yet</h2>
                <p class="mt-2 text-muted">When you buy something, it shows up here with its download links.</p>
                <a href="{{ route('products') }}" class="btn btn-primary mt-6">Browse products</a>
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
                                <a href="{{ $order->url() }}" class="inline-flex min-h-11 items-center font-medium text-accent hover:underline">View order</a>
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
                                                <a href="{{ $grant->url() }}" class="chip-link">@include('storefront.partials.icon', ['name' => 'download', 'size' => 16])<span class="ml-1.5">{{ $grant->asset?->filename ?? 'Download' }}</span></a>
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
    </div>
@endsection
