<?php

namespace App\Services\Reviews;

use App\Mail\ReviewRequestMail;
use App\Models\Order;
use App\Models\Review;
use App\Models\ReviewRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Review-request emails. This store sells downloads, so an order counts as delivered the moment
 * it is paid: the request goes out a set number of days after payment.
 */
class ReviewRequests
{
    public function __construct(private readonly ReviewSettings $settings) {}

    /** Give every bought item its own review link, due after the delay in the settings. */
    public function schedule(Order $order): void
    {
        $sendAt = ($order->paid_at ?? now())->copy()->addDays((int) $this->settings->get('request_delay_days'));

        foreach ($order->items()->whereNotNull('product_id')->get() as $item) {
            ReviewRequest::query()->firstOrCreate(['order_item_id' => $item->id], ['token' => Str::random(48), 'send_at' => $sendAt]);
        }
    }

    /** Send what is due: one email per order, with a link for each item. Returns how many emails went out. */
    public function sendDue(): int
    {
        if (! $this->settings->get('request_enabled')) {
            return 0;
        }

        $due = ReviewRequest::query()->whereNull('sent_at')->whereNull('used_at')->where('send_at', '<=', now())
            ->with('orderItem.order', 'orderItem.product')->limit(500)->get()
            ->filter(fn (ReviewRequest $r) => $r->orderItem?->order)
            ->groupBy(fn (ReviewRequest $r) => $r->orderItem->order_id);

        $sent = 0;
        foreach ($due as $requests) {
            $order = $requests->first()->orderItem->order;
            // No request for refunded or cancelled orders, for removed products, or for items already reviewed.
            $reviewed = Review::query()->whereIn('order_item_id', $requests->pluck('order_item_id'))->pluck('order_item_id');
            $wanted = $requests->filter(fn (ReviewRequest $r) => $order->status === Order::PAID && $r->orderItem->product && ! $reviewed->contains($r->order_item_id))->values();

            if ($wanted->isNotEmpty()) {
                Mail::to($order->email)->queue(new ReviewRequestMail($order, $wanted));
                $order->record('email_queued', 'Review request email queued');
                $sent++;
            }
            ReviewRequest::query()->whereKey($requests->modelKeys())->update(['sent_at' => now()]);
        }

        return $sent;
    }
}
