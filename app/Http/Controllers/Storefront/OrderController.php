<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\DownloadGrant;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    /** Confirmation page. The link is signed, so only someone holding it can open the order. */
    public function show(string $number): View
    {
        $order = Order::query()->where('number', $number)->with(['items.downloads.asset', 'items.product.images', 'payment'])->firstOrFail();

        return view('storefront.order', [
            'order' => $order,
            'statusUrl' => URL::signedRoute('order.status', ['number' => $order->number]),
        ]);
    }

    /** Polled by the confirmation page while it waits for Stripe's webhook. */
    public function status(string $number): JsonResponse
    {
        $order = Order::query()->where('number', $number)->with('payment')->firstOrFail();

        return response()->json([
            'status' => $order->status,
            'paid' => $order->wasPaid(),
            'failed' => $order->status === Order::FAILED,
            'message' => $order->status === Order::FAILED ? ($order->payment?->failure_message ?: 'The payment did not go through.') : null,
        ]);
    }

    /** A buyer's own download link: checked against its limits, logged, and streamed from the private disk. */
    public function download(Request $request, string $token): StreamedResponse|Response
    {
        $grant = DownloadGrant::query()->where('token', $token)->with('asset')->first();
        $problem = match (true) {
            ! $grant || ! $grant->asset => 'This download link is not valid.',
            default => $grant->blockedReason(),
        };
        if (! $problem && ! Storage::disk($grant->asset->disk)->exists($grant->asset->path)) {
            $problem = 'This file is not available right now. Please contact support.';
        }
        if ($problem) {
            return response()->view('storefront.download-blocked', ['problem' => $problem], $grant ? 410 : 404);
        }

        $grant->increment('downloads_used');
        $grant->logs()->create(['ip' => $request->ip()]);

        return Storage::disk($grant->asset->disk)->download($grant->asset->path, $grant->asset->filename, [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
