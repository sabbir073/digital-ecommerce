<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\Order;
use App\Models\ReviewRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ReviewRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    /** @param Collection<int, ReviewRequest> $requests */
    public function __construct(public Order $order, public Collection $requests) {}

    protected function templateKey(): string
    {
        return 'review_request';
    }

    protected function templateVariables(): array
    {
        return ['customer_name' => $this->order->name ?: 'there', 'order_number' => $this->order->number];
    }

    protected function templateBlocks(): array
    {
        $this->requests->loadMissing('orderItem');

        return ['review_buttons' => ['type' => 'buttons', 'buttons' => $this->requests
            ->map(fn (ReviewRequest $request) => ['label' => 'Review '.$request->orderItem->name, 'url' => $request->url()])->all()]];
    }
}
