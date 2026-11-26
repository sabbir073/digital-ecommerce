<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $orders = Order::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->when(array_key_exists($status, Order::STATUS_LABELS), fn ($q) => $q->where('status', $status))
            // Abandoned checkouts are noise unless someone asks for them.
            ->when($status === '', fn ($q) => $q->whereNotIn('status', [Order::CANCELLED, Order::PENDING]))
            ->withCount('items')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Order $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'email' => $o->email,
                'name' => $o->name,
                'status' => $o->status,
                'statusLabel' => $o->statusLabel(),
                'source' => $o->source,
                'items' => $o->items_count,
                'total' => (int) $o->total_cents,
                'createdAt' => $o->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => ['search' => $search, 'status' => $status],
            'statuses' => Order::STATUS_LABELS,
        ]);
    }

    public function show(Order $order): Response
    {
        $order->load(['items.downloads.asset', 'events', 'payments.refunds', 'payments.disputes']);
        $payment = $order->payments->sortByDesc('id')->first();

        return Inertia::render('Orders/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'email' => $order->email,
                'name' => $order->name,
                'status' => $order->status,
                'statusLabel' => $order->statusLabel(),
                'source' => $order->source,
                'country' => $order->billing_country ? (config('countries')[$order->billing_country] ?? $order->billing_country) : null,
                'postcode' => $order->billing_postcode,
                'couponCode' => $order->coupon_code,
                'subtotal' => (int) $order->subtotal_cents,
                'discount' => (int) $order->discount_cents,
                'tax' => (int) $order->tax_cents,
                'total' => (int) $order->total_cents,
                'refunded' => (int) $order->refunded_cents,
                'createdAt' => $order->created_at?->toIso8601String(),
                'paidAt' => $order->paid_at?->toIso8601String(),
                'items' => $order->items->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'variant' => $i->variant_label,
                    'sku' => $i->sku,
                    'unit' => (int) $i->unit_price_cents,
                    'quantity' => $i->quantity,
                    'total' => (int) $i->line_total_cents,
                    'productId' => $i->product_id,
                    'downloads' => $i->downloads->map(fn ($g) => [
                        'file' => $g->asset?->filename,
                        'used' => $g->downloads_used,
                        'max' => $g->max_downloads,
                        'expiresAt' => $g->expires_at?->toIso8601String(),
                        'revoked' => $g->revoked_at !== null,
                    ])->values(),
                ])->values(),
                'payment' => $payment ? [
                    'intent' => $payment->stripe_payment_intent_id,
                    'status' => $payment->status,
                    'brand' => $payment->card_brand,
                    'last4' => $payment->card_last4,
                    'failure' => $payment->failure_message,
                    'refunds' => $payment->refunds->map(fn ($r) => ['id' => $r->stripe_refund_id, 'amount' => (int) $r->amount_cents, 'reason' => $r->reason, 'at' => $r->created_at?->toIso8601String()])->values(),
                    'disputes' => $payment->disputes->map(fn ($d) => ['id' => $d->stripe_dispute_id, 'status' => $d->status, 'amount' => (int) $d->amount_cents, 'reason' => $d->reason])->values(),
                ] : null,
                'events' => $order->events->map(fn ($e) => ['type' => $e->type, 'note' => $e->note, 'at' => $e->created_at?->toIso8601String()])->values(),
            ],
        ]);
    }
}
