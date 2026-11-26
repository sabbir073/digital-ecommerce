<?php

namespace App\Services\Integrations;

use App\Models\EventLog;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends events from the server to the services that take them: Meta Conversions API and TikTok Events API.
 * See docs/integrations.md for the rules each request follows.
 */
class ServerEvents
{
    public const META_VERSION = 'v26.0';

    private const META = ['page_view' => 'PageView', 'view_item_list' => 'ViewCategory', 'view_item' => 'ViewContent', 'search' => 'Search', 'add_to_cart' => 'AddToCart',
        'begin_checkout' => 'InitiateCheckout', 'add_payment_info' => 'AddPaymentInfo', 'purchase' => 'Purchase', 'sign_up' => 'CompleteRegistration', 'submit_review' => 'SubmitReview'];

    private const TIKTOK = ['view_item_list' => 'ViewItemList', 'view_item' => 'ViewContent', 'search' => 'Search', 'add_to_cart' => 'AddToCart',
        'begin_checkout' => 'InitiateCheckout', 'add_payment_info' => 'AddPaymentInfo', 'purchase' => 'Purchase', 'sign_up' => 'CompleteRegistration', 'submit_review' => 'SubmitReview'];

    public function __construct(private readonly Integrations $integrations) {}

    /**
     * @param  array{name:string, id:string, data:array<string, mixed>}  $event
     * @param  array<string, mixed>  $visitor  from Tracking::visitor(), plus email, name and country when known
     * @param  bool  $test  a test event from admin: sent even without a visitor's consent, because there is no visitor
     * @return array<string, array{ok:bool, response:string}> what each service answered
     */
    public function send(array $event, array $visitor, bool $test = false, ?array $only = null): array
    {
        $results = [];
        if (! $test && ! ($visitor['marketing'] ?? false)) {
            return $results;
        }
        if ($this->integrations->active('meta_capi') && ($only === null || in_array('meta_capi', $only, true))) {
            $results['meta_capi'] = $this->meta($event, $visitor);
        }
        if ($this->integrations->active('tiktok') && $this->integrations->setting('tiktok', 'access_token') !== '' && isset(self::TIKTOK[$event['name']])
            && ($only === null || in_array('tiktok', $only, true))) {
            $results['tiktok'] = $this->tiktok($event, $visitor);
        }

        return $results;
    }

    /** @return array{ok:bool, response:string} */
    private function meta(array $event, array $visitor): array
    {
        $data = $event['data'];
        $items = $data['items'] ?? [];
        $custom = array_filter([
            'value' => $data['value'] ?? null,
            'currency' => $data['currency'] ?? null,
            'content_type' => $items ? 'product' : null,
            'content_ids' => $items ? array_column($items, 'id') : null,
            'contents' => $items ? array_map(fn ($i) => ['id' => $i['id'], 'quantity' => $i['quantity'], 'item_price' => $i['price']], $items) : null,
            'order_id' => $data['order'] ?? null,
            'num_items' => $event['name'] === 'begin_checkout' ? array_sum(array_column($items, 'quantity')) : null,
            'search_string' => $data['term'] ?? null,
        ], fn ($value) => $value !== null);

        $payload = ['data' => [array_filter([
            'event_name' => self::META[$event['name']] ?? $event['name'],
            'event_time' => time(),
            'event_id' => $event['id'],
            'action_source' => 'website',
            'event_source_url' => $visitor['url'] ?? url('/'),
            'user_data' => array_filter([
                'em' => self::hash($visitor['email'] ?? null),
                'fn' => self::hash(self::namePart($visitor['name'] ?? null, 0)),
                'ln' => self::hash(self::namePart($visitor['name'] ?? null, 1)),
                'country' => self::hash($visitor['country'] ?? null),
                'client_ip_address' => $visitor['ip'] ?? null,
                'client_user_agent' => $visitor['user_agent'] ?? null,
                'fbp' => $visitor['fbp'] ?? null,
                'fbc' => $visitor['fbc'] ?? null,
            ]),
            'custom_data' => $custom ?: null,
        ])]];
        $code = $this->integrations->setting('meta_capi', 'test_event_code');
        if ($code !== '') {
            $payload['test_event_code'] = $code;
        }

        $pixel = $this->integrations->setting('meta_pixel', 'pixel_id');
        $url = 'https://graph.facebook.com/'.self::META_VERSION.'/'.$pixel.'/events';

        return $this->post('meta_capi', $event['name'], $payload, fn () => Http::timeout(6)->acceptJson()
            ->post($url.'?access_token='.urlencode($this->integrations->setting('meta_capi', 'access_token')), $payload));
    }

    /** @return array{ok:bool, response:string} */
    private function tiktok(array $event, array $visitor): array
    {
        $data = $event['data'];
        $items = $data['items'] ?? [];
        $payload = array_filter([
            'event_source' => 'web',
            'event_source_id' => $this->integrations->setting('tiktok', 'pixel_id'),
            'test_event_code' => $this->integrations->setting('tiktok', 'test_event_code') ?: null,
            'data' => [array_filter([
                'event' => self::TIKTOK[$event['name']],
                'event_time' => time(),
                'event_id' => $event['id'],
                'user' => array_filter([
                    'email' => self::hash($visitor['email'] ?? null),
                    'ip' => $visitor['ip'] ?? null,
                    'user_agent' => $visitor['user_agent'] ?? null,
                    'ttp' => $visitor['ttp'] ?? null,
                ]),
                'properties' => array_filter([
                    'value' => $data['value'] ?? null,
                    'currency' => $data['currency'] ?? null,
                    'content_type' => $items ? 'product' : null,
                    'contents' => $items ? array_map(fn ($i) => ['content_id' => $i['id'], 'content_name' => $i['name'], 'quantity' => $i['quantity'], 'price' => $i['price']], $items) : null,
                    'order_id' => $data['order'] ?? null,
                    'search_string' => $data['term'] ?? null,
                ], fn ($value) => $value !== null),
                'page' => ['url' => $visitor['url'] ?? url('/')],
            ])],
        ]);

        return $this->post('tiktok', $event['name'], $payload, fn () => Http::timeout(6)->acceptJson()
            ->withHeaders(['Access-Token' => $this->integrations->setting('tiktok', 'access_token')])
            ->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', $payload));
    }

    /** @return array{ok:bool, response:string} */
    private function post(string $service, string $event, array $payload, callable $request): array
    {
        try {
            $response = $request();
            $body = (string) $response->body();
            // TikTok answers 200 with its own code; anything but 0 is a refusal.
            $ok = $response->successful() && ($service !== 'tiktok' || (int) ($response->json('code') ?? -1) === 0);
            $problem = $ok ? null : ($response->json('error.message') ?? $response->json('message') ?? 'The service answered '.$response->status().'.');
        } catch (Throwable $e) {
            [$ok, $body, $problem] = [false, $e->getMessage(), 'The service could not be reached: '.$e->getMessage()];
        }

        try {
            EventLog::record($event, $service, $payload, $body, $ok);
        } catch (Throwable $e) {
            // The log is for debugging; a full disk must not stop an order.
        }
        $this->integrations->report($service, $ok, $problem);

        return ['ok' => $ok, 'response' => $ok ? 'Accepted.' : (string) $problem];
    }

    /** SHA-256 of the trimmed, lowercase value, as both services ask for. */
    private static function hash(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : hash('sha256', $value);
    }

    private static function namePart(?string $name, int $index): ?string
    {
        $parts = preg_split('/\s+/', trim((string) $name), 2) ?: [];

        return isset($parts[$index]) ? preg_replace('/[^\p{L}]/u', '', $parts[$index]) : null;
    }
}
