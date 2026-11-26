<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrderConfirmation;
use App\Mail\RefundIssued;
use App\Models\Order;
use App\Services\ActivityLogger;
use App\Services\Checkout\OrderFulfillment;
use App\Services\Payments\PaymentGateway;
use App\Support\Csv;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OrderController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** @return array{search:string, status:string, source:string, from:string, to:string} */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) ? (string) $request->query($key) : '';

        return [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'source' => in_array($request->query('source'), ['cart', 'selling_page'], true) ? (string) $request->query('source') : '',
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }

    /** @param array{search:string, status:string, source:string, from:string, to:string} $f */
    private function filtered(array $f): Builder
    {
        $search = $f['search'];
        $status = $f['status'];

        return Order::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->when(array_key_exists($status, Order::STATUS_LABELS), fn ($q) => $q->where('status', $status))
            // Abandoned checkouts are noise unless someone asks for them.
            ->when($status === '', fn ($q) => $q->whereNotIn('status', [Order::CANCELLED, Order::PENDING]))
            ->when($f['source'] !== '', fn ($q) => $q->where('source', $f['source']))
            ->when($f['from'] !== '', fn ($q) => $q->where('created_at', '>=', Carbon::parse($f['from'])->startOfDay()))
            ->when($f['to'] !== '', fn ($q) => $q->where('created_at', '<=', Carbon::parse($f['to'])->endOfDay()));
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $orders = $this->filtered($filters)
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
            'filters' => $filters,
            'statuses' => Order::STATUS_LABELS,
        ]);
    }

    public function show(Order $order): Response
    {
        $order->load(['items.downloads.asset', 'events', 'payments.refunds', 'payments.disputes']);
        $payment = $order->payments->sortByDesc('id')->first();
        $staff = DB::table('admin_users')->whereIn('id', $order->events->pluck('admin_user_id')->filter()->unique())->pluck('name', 'id');

        return Inertia::render('Orders/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'email' => $order->email,
                'name' => $order->name,
                'phone' => $order->phone,
                'customerNote' => $order->customer_note,
                'status' => $order->status,
                'statusLabel' => $order->statusLabel(),
                'source' => $order->source,
                'sellingPage' => $order->sellingPage ? ['id' => $order->sellingPage->id, 'name' => $order->sellingPage->name, 'deleted' => $order->sellingPage->trashed()] : null,
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
                'events' => $order->events->map(fn ($e) => ['type' => $e->type, 'note' => $e->note, 'at' => $e->created_at?->toIso8601String(), 'by' => $e->admin_user_id ? ($staff[$e->admin_user_id] ?? 'Staff') : null])->values(),
                'refundable' => $order->wasPaid() && $payment && $payment->status === 'succeeded' ? max(0, (int) $order->total_cents - (int) $order->refunded_cents) : 0,
                'wasPaid' => $order->wasPaid(),
                'hasDownloads' => $order->items->contains(fn ($i) => $i->downloads->isNotEmpty()),
            ],
        ]);
    }

    /** The orders the list is showing, as a spreadsheet. */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($this->filters($request))->with('items')->orderBy('id');
        $this->activity->log('export', 'orders', null, 'Orders CSV');

        return Csv::download('orders', ['number', 'date', 'status', 'email', 'name', 'country', 'source', 'items', 'subtotal', 'discount', 'coupon', 'tax', 'total', 'refunded', 'paid_at'], (function () use ($query) {
            foreach ($query->lazy(200) as $o) {
                yield [
                    $o->number, $o->created_at?->toDateTimeString(), $o->statusLabel(), $o->email, $o->name, $o->billing_country, $o->source,
                    $o->items->map(fn ($i) => $i->quantity.' x '.$i->name.($i->variant_label ? ' ('.$i->variant_label.')' : ''))->implode('; '),
                    Csv::money($o->subtotal_cents), Csv::money($o->discount_cents), $o->coupon_code, Csv::money($o->tax_cents), Csv::money($o->total_cents),
                    Csv::money($o->refunded_cents), $o->paid_at?->toDateTimeString(),
                ];
            }
        })());
    }

    /** Send money back through Stripe. A full refund also cancels the download links. */
    public function refund(Request $request, Order $order, PaymentGateway $gateway, OrderFulfillment $fulfillment): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:200'],
        ], ['amount.required' => 'Enter the amount to refund.']);

        $payment = $order->payments()->where('status', 'succeeded')->latest('id')->first();
        if (! $order->wasPaid() || ! $payment) {
            return back()->with('error', 'This order has no card payment to refund.');
        }

        $amount = (int) Money::toCents($data['amount']);
        $left = (int) $order->total_cents - (int) $order->refunded_cents;
        if ($amount < 1 || $amount > $left) {
            return back()->withErrors(['amount' => $left > 0 ? 'The most that can be refunded is '.Money::format($left).'.' : 'This order is already refunded in full.']);
        }

        try {
            // The key ties the request to the order's refunded total, so a double click cannot refund twice.
            $refund = $gateway->refund($payment->stripe_payment_intent_id, $amount, "refund-{$order->id}-{$order->refunded_cents}-{$amount}", $data['reason'] ?? null);
        } catch (Throwable $e) {
            Log::warning('Refund failed', ['order' => $order->number, 'message' => $e->getMessage()]);
            $order->record('refund_failed', 'Stripe did not accept the refund: '.$e->getMessage(), Auth::guard('admin')->id());

            return back()->with('error', 'Stripe did not accept the refund: '.$e->getMessage());
        }

        $fulfillment->recordRefund($payment->stripe_payment_intent_id, $refund['id'], $refund['amount'], $data['reason'] ?? null, Auth::guard('admin')->id());
        $order->refresh();
        $this->activity->log('refund', 'refunds', $order, 'Order '.$order->number, ['amount' => Money::format($refund['amount']), 'reason' => $data['reason'] ?? null]);

        try {
            Mail::to($order->email)->queue(new RefundIssued($order, $refund['amount']));
        } catch (Throwable $e) {
            $order->record('email_failed', 'Refund email could not be queued: '.$e->getMessage());
        }

        return back()->with('success', Money::format($refund['amount']).' refunded.'.($order->status === Order::REFUNDED ? ' The download links were cancelled.' : ''));
    }

    /** Send the confirmation email, with its download links, again. */
    public function resend(Order $order): RedirectResponse
    {
        if (! $order->wasPaid()) {
            return back()->with('error', 'Only paid orders have a confirmation email.');
        }

        Mail::to($order->email)->queue(new OrderConfirmation($order));
        $order->record('email_queued', 'Confirmation email sent again', Auth::guard('admin')->id());
        $this->activity->log('edit', 'orders', $order, 'Order '.$order->number, ['email' => 'confirmation sent again']);

        return back()->with('success', 'Confirmation email sent to '.$order->email.'.');
    }

    /** Give the customer fresh download links: the count starts again and the time limit is extended. */
    public function renewDownloads(Order $order): RedirectResponse
    {
        if (! $order->wasPaid() || $order->status === Order::REFUNDED) {
            return back()->with('error', 'Downloads can only be renewed for a paid order that was not refunded in full.');
        }

        $days = (int) config('store.download_days');
        $count = DB::table('download_grants')->whereIn('order_item_id', $order->items()->pluck('id'))
            ->update(['downloads_used' => 0, 'revoked_at' => null, 'expires_at' => $days > 0 ? now()->addDays($days) : null, 'updated_at' => now()]);
        if ($count === 0) {
            return back()->with('error', 'This order has no download files.');
        }

        $order->record('downloads_renewed', 'Download links renewed', Auth::guard('admin')->id());
        $this->activity->log('edit', 'orders', $order, 'Order '.$order->number, ['downloads' => 'renewed']);

        return back()->with('success', 'Download links renewed. Send the confirmation email again so the customer has them.');
    }

    /** Give up on an order that was never paid. */
    public function cancel(Order $order, OrderFulfillment $fulfillment): RedirectResponse
    {
        if ($order->wasPaid()) {
            return back()->with('error', 'A paid order cannot be cancelled. Refund it instead.');
        }

        $fulfillment->cancel($order, 'Cancelled by '.Auth::guard('admin')->user()->name);
        if ($order->refresh()->status !== Order::CANCELLED) {
            return back()->with('error', 'The payment is already going through, so the order was not cancelled.');
        }
        $this->activity->log('edit', 'orders', $order, 'Order '.$order->number, ['status' => 'cancelled']);

        return back()->with('success', 'Order cancelled.');
    }

    /** A note for the team. Customers never see it. */
    public function note(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:250']]);
        $order->record('note', $data['note'], Auth::guard('admin')->id());

        return back()->with('success', 'Note added.');
    }
}
