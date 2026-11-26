<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\ReviewRequest;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ReviewRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param Collection<int, ReviewRequest> $requests */
    public function __construct(public Order $order, public Collection $requests) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'How was your order '.$this->order->number.'?');
    }

    public function content(): Content
    {
        $this->requests->loadMissing('orderItem');

        return new Content(markdown: 'emails.review-request', with: [
            'order' => $this->order,
            'requests' => $this->requests,
            'storeName' => Setting::get('store.name', config('app.name')),
        ]);
    }
}
