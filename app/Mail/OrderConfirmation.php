<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your order {$this->order->number} and download links");
    }

    public function content(): Content
    {
        $this->order->loadMissing('items.downloads.asset');

        return new Content(markdown: 'emails.order-confirmation', with: [
            'order' => $this->order,
            'storeName' => Setting::get('store.name', config('app.name')),
        ]);
    }
}
