<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ConsentRecord;
use App\Models\EventLog;
use App\Models\Setting;
use App\Services\Integrations\Consent;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\ServerEvents;
use App\Services\Integrations\Tracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/** Where the storefront's tracking script reports to: events for the server-side services and the log, and cookie choices. */
class TrackingController extends Controller
{
    /**
     * An event the browser has just sent to its tags. The server passes it on to the services that take
     * server events, with the same event ID, and notes it in the event log. Purchase never comes this way:
     * it is sent when payment is confirmed.
     */
    public function event(Request $request, Integrations $integrations, Consent $consent, ServerEvents $server): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', Rule::in(array_diff(Tracking::EVENTS, ['purchase']))],
            'id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'url' => ['nullable', 'string', 'max:500'],
            'sent' => ['array', 'max:12'],
            'sent.*' => [Rule::in(array_keys(Integrations::SERVICES))],
            'data' => ['array'],
            'data.value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'data.currency' => ['nullable', 'string', 'size:3'],
            'data.term' => ['nullable', 'string', 'max:100'],
            'data.list' => ['nullable', 'string', 'max:120'],
            'data.rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'data.items' => ['array', 'max:30'],
            'data.items.*.id' => ['required', 'string', 'max:40'],
            'data.items.*.name' => ['nullable', 'string', 'max:200'],
            'data.items.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'data.items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $event = ['name' => $data['name'], 'id' => $data['id'], 'data' => array_intersect_key($data['data'] ?? [], array_flip(['value', 'currency', 'term', 'list', 'rating', 'items']))];
        $event['data']['items'] = array_map(fn (array $item) => ['id' => $item['id'], 'name' => (string) ($item['name'] ?? ''), 'price' => (float) ($item['price'] ?? 0), 'quantity' => (int) ($item['quantity'] ?? 1)], $event['data']['items'] ?? []);
        if ($event['data']['items'] === []) {
            unset($event['data']['items']);
        }

        $sent = array_values(array_filter($data['sent'] ?? [], fn (string $service) => $integrations->active($service)));
        if ($sent !== [] && Setting::get('integrations.log_browser_events', true)) {
            try {
                EventLog::record($event['name'], 'browser', $event + ['url' => $data['url'] ?? null], 'Sent by the visitor\'s browser to: '.implode(', ', array_map(fn ($s) => Integrations::SERVICES[$s]['name'], $sent)).'.');
                foreach ($sent as $service) {
                    $integrations->report($service, true);
                }
            } catch (Throwable $e) {
                // The log is for debugging only.
            }
        }

        if ($integrations->hasServerSide() && $data['name'] !== 'page_view') {
            $visitor = Tracking::visitor($request, $consent);
            $visitor['url'] = $data['url'] ?? $visitor['url'] ?? null;
            dispatch(fn () => $server->send($event, $visitor))->afterResponse();
        }

        return response()->json(['ok' => true]);
    }

    /** Proof of what a visitor chose on the cookie banner. */
    public function consent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor' => ['required', 'string', 'regex:/^[a-z0-9]{8,40}$/'],
            'analytics' => ['required', 'boolean'],
            'marketing' => ['required', 'boolean'],
        ]);

        ConsentRecord::query()->updateOrCreate(['visitor_id' => $data['visitor']], [
            'categories' => ['necessary' => true, 'analytics' => (bool) $data['analytics'], 'marketing' => (bool) $data['marketing']],
            'region' => strtoupper(substr((string) $request->header('CF-IPCountry', ''), 0, 2)) ?: null,
        ]);

        return response()->json(['ok' => true]);
    }
}
