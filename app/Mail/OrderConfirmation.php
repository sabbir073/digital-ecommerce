<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEmailTemplate;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesEmailTemplate;

    public function __construct(public Order $order) {}

    protected function templateKey(): string
    {
        return 'order_confirmation';
    }

    protected function templateVariables(): array
    {
        return ['customer_name' => $this->order->name ?: 'there', 'order_number' => $this->order->number, 'order_total' => Money::format($this->order->total_cents)];
    }

    protected function templateBlocks(): array
    {
        $this->order->loadMissing('items.downloads.asset');

        return [
            'downloads' => ['type' => 'view', 'view' => 'emails.parts.downloads', 'data' => ['order' => $this->order]],
            'order_table' => ['type' => 'view', 'view' => 'emails.parts.order-table', 'data' => ['order' => $this->order]],
            'button' => ['type' => 'button', 'url' => $this->order->url(), 'label' => 'View your order'],
        ];
    }
}
