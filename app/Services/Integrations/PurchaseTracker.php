<?php

namespace App\Services\Integrations;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Makes sure the Purchase event of an order goes out once from the server and once to the buyer's browser, never twice. */
class PurchaseTracker
{
    public function __construct(private readonly ServerEvents $server) {}

    /** Called when payment is confirmed. */
    public function sendFromServer(Order $order): void
    {
        try {
            // Whoever sets the timestamp sends; a second webhook or a retry finds it set and does nothing.
            $claimed = Order::query()->whereKey($order->id)->whereNotNull('paid_at')->whereNull('purchase_event_sent_at')->update(['purchase_event_sent_at' => now()]);
            if ($claimed !== 1) {
                return;
            }
            $visitor = (array) $order->tracking + ['email' => $order->email, 'name' => $order->name, 'country' => $order->billing_country, 'ip' => $order->ip];
            $this->server->send(Tracking::purchase($order), $visitor);
        } catch (Throwable $e) {
            Log::warning('Purchase event could not be sent from the server', ['order' => $order->number, 'message' => $e->getMessage()]);
        }
    }

    /**
     * The Purchase event for the buyer's browser, the first time a paid order is shown; null after that,
     * so refreshing the confirmation page sends nothing.
     *
     * @return array<string, mixed>|null
     */
    public function claimForBrowser(Order $order): ?array
    {
        if (! $order->wasPaid()) {
            return null;
        }
        $claimed = Order::query()->whereKey($order->id)->whereNull('purchase_tracked_at')->update(['purchase_tracked_at' => now()]);
        if ($claimed !== 1) {
            return null;
        }
        $event = Tracking::purchase($order);
        // For enhanced conversions; hashed in the browser and only used when the buyer allowed marketing cookies.
        $event['data']['email'] = $order->email;

        return $event;
    }
}
