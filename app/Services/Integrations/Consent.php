<?php

namespace App\Services\Integrations;

use App\Models\Setting;
use Illuminate\Http\Request;

/** The cookie banner: its text, its categories, who has to be asked, and what a visitor chose (PRD section 14). */
class Consent
{
    public const COOKIE = 'cc';

    /** The European Economic Area, the United Kingdom and Switzerland. */
    private const EUROPE = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH'];

    public const REGIONS = ['all' => 'Every visitor', 'europe' => 'Only visitors from Europe (EEA, United Kingdom, Switzerland)'];

    public const DEFAULTS = [
        'enabled' => true,
        'region' => 'all',
        'title' => 'Cookies on this store',
        'text' => 'We use cookies that the store needs to work. With your permission we also use cookies to measure visits and to show you relevant ads.',
        'accept' => 'Accept all',
        'reject' => 'Only necessary',
        'choose' => 'Choose',
        'save' => 'Save my choice',
        'policy_page_id' => null,
        'categories' => [
            'necessary' => ['label' => 'Necessary', 'text' => 'Your cart, sign-in and payment. The store cannot work without them, so they are always on.'],
            'analytics' => ['label' => 'Statistics', 'text' => 'Counts visits and shows us which pages and products are used, so we can improve the store.'],
            'marketing' => ['label' => 'Marketing', 'text' => 'Lets advertising services measure our ads and show you ads that fit your interests.'],
        ],
    ];

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $saved = (array) Setting::get('consent', []);
        $settings = array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        foreach (self::DEFAULTS['categories'] as $key => $defaults) {
            $settings['categories'][$key] = [
                'label' => trim((string) ($saved['categories'][$key]['label'] ?? '')) ?: $defaults['label'],
                'text' => trim((string) ($saved['categories'][$key]['text'] ?? '')) ?: $defaults['text'],
            ];
        }
        $settings['enabled'] = (bool) $settings['enabled'];
        $settings['region'] = isset(self::REGIONS[$settings['region']]) ? $settings['region'] : 'all';

        return $settings;
    }

    /**
     * Must this visitor be asked? True or false when the server can tell; null when only the browser can
     * (Europe-only mode without a country from the network in front of the site: the browser then goes by its time zone).
     */
    public function required(Request $request): ?bool
    {
        $settings = $this->settings();
        if (! $settings['enabled']) {
            return false;
        }
        if ($settings['region'] === 'all') {
            return true;
        }
        $country = strtoupper((string) $request->header('CF-IPCountry', ''));

        return preg_match('/^[A-Z]{2}$/', $country) && $country !== 'XX' ? in_array($country, self::EUROPE, true) : null;
    }

    /**
     * What the visitor's browser recorded: "a1m0.visitorid". The browser writes it as soon as it knows,
     * including when nobody has to be asked, so the server never guesses.
     *
     * @return array{analytics:bool, marketing:bool, visitor:string}|null
     */
    public function choice(Request $request): ?array
    {
        $raw = (string) ($request->cookies->get(self::COOKIE) ?? '');
        if (! preg_match('/^a([01])m([01])\.([a-z0-9]{8,40})$/', $raw, $m)) {
            return null;
        }

        return ['analytics' => $m[1] === '1', 'marketing' => $m[2] === '1', 'visitor' => $m[3]];
    }

    /** No recorded choice means no: a server event is never sent on a guess. */
    public function allows(Request $request, string $category): bool
    {
        if ($this->required($request) === false) {
            return true;
        }

        return (bool) ($this->choice($request)[$category] ?? false);
    }
}
