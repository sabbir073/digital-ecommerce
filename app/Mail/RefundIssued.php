<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RefundIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function __construct(public Order $order, public int $amountCents) {}

    protected function templateKey(): string
    {
        return 'refund_issued';
    }

    protected function templateVariables(): array
    {
        return [
            'customer_name' => $this->order->name ?: 'there',
            'order_number' => $this->order->number,
            'order_total' => Money::format($this->order->total_cents),
            'refund_amount' => Money::format($this->amountCents),
        ];
    }

    protected function templateBlocks(): array
    {
        return [
            'full_refund_note' => $this->order->status === Order::REFUNDED
                ? ['type' => 'text', 'text' => 'Because the order was refunded in full, its download links no longer work.']
                : null,
            'button' => ['type' => 'button', 'url' => $this->order->url(), 'label' => 'View your order'],
        ];
    }
}
