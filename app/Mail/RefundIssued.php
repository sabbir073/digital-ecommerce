<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RefundIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public int $amountCents) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Refund for your order {$this->order->number}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.refund-issued', with: [
            'order' => $this->order,
            'amount' => Money::format($this->amountCents),
            'full' => $this->order->status === Order::REFUNDED,
            'storeName' => Setting::get('store.name', config('app.name')),
        ]);
    }
}
