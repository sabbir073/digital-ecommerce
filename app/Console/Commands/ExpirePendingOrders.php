<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Checkout\OrderFulfillment;
use Illuminate\Console\Command;

class ExpirePendingOrders extends Command
{
    protected $signature = 'orders:expire-pending';

    protected $description = 'Cancel unpaid orders whose stock hold has run out and free their stock';

    public function handle(OrderFulfillment $fulfillment): int
    {
        // Twice the hold time, so a customer still on the payment form is never cut off.
        $cutoff = now()->subMinutes(2 * (int) config('store.reservation_minutes'));
        $count = 0;

        Order::query()->whereIn('status', [Order::PENDING, Order::FAILED])->where('updated_at', '<', $cutoff)
            ->with('payments')->chunkById(100, function ($orders) use ($fulfillment, &$count) {
                foreach ($orders as $order) {
                    $fulfillment->cancel($order, 'Not paid in time');
                    $count += $order->refresh()->status === Order::CANCELLED ? 1 : 0;
                }
            });

        $this->info("Cancelled {$count} unpaid orders.");

        return self::SUCCESS;
    }
}
