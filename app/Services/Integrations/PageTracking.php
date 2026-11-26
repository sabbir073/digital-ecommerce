<?php

namespace App\Services\Integrations;

use App\Models\ContentPage;
use App\Models\SellingPage;
use App\Models\Setting;
use Illuminate\Http\Request;

/** Everything the tracking script of one storefront page needs: which tags, the consent rules, and the events to send. */
class PageTracking
{
    public function __construct(private readonly Integrations $integrations, private readonly Consent $consent) {}

    /**
     * @param  list<array<string, mixed>>  $events  what this page itself reports (view item, purchase, ...)
     * @return array{config:array<string, mixed>, banner:?array<string, mixed>}|null null when there is nothing to load
     */
    public function for(Request $request, array $events = [], ?SellingPage $page = null): ?array
    {
        $services = $this->integrations->browser($page);
        $required = $this->consent->required($request);
        $log = (bool) Setting::get('integrations.log_browser_events', true);
        $relay = $this->integrations->hasServerSide() || ($log && $services !== []);

        // With no service switched on there is nothing to consent to and nothing to send.
        if ($services === []) {
            return null;
        }

        $settings = $this->consent->settings();
        $policy = $settings['policy_page_id'] ? ContentPage::query()->published()->find($settings['policy_page_id']) : null;

        return [
            'config' => [
                'services' => $services,
                'consent' => ['required' => $required, 'cookie' => Consent::COOKIE, 'url' => route('consent.store')],
                'relay' => $relay ? route('events.store') : null,
                'events' => array_values(array_merge(Tracking::pullFlashed(), $events)),
                'currency' => 'USD',
            ],
            'banner' => $required === false ? null : [
                'title' => $settings['title'], 'text' => $settings['text'], 'accept' => $settings['accept'], 'reject' => $settings['reject'],
                'choose' => $settings['choose'], 'save' => $settings['save'], 'categories' => $settings['categories'],
                'policy' => $policy ? ['title' => $policy->title, 'url' => $policy->url()] : null,
            ],
        ];
    }
}
