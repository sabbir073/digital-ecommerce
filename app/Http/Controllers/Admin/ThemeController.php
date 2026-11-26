<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\StoreSettings;
use App\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Theme settings: logo, favicon, accent colour, fonts, product grid and social links. */
class ThemeController extends Controller
{
    public function edit(StoreSettings $settings): Response
    {
        $theme = $settings->theme();

        return Inertia::render('Settings/Theme', [
            'theme' => $theme,
            'media' => Media::query()->whereIn('id', array_filter([$theme['logo_media_id'], $theme['favicon_media_id']]))->get()->map->toAdminArray()->keyBy('id'),
            'options' => [
                'accents' => collect(Theme::ACCENTS)->map(fn ($name, $hex) => ['hex' => $hex, 'name' => $name])->values(),
                'fonts' => collect(Theme::FONTS)->map(fn ($font, $key) => ['key' => $key, 'name' => $font['name'], 'stack' => $font['stack']])->values(),
                'densities' => Theme::DENSITIES,
                'socials' => Theme::SOCIALS,
            ],
            'storeUrl' => url('/'),
        ]);
    }

    public function update(Request $request, StoreSettings $settings, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'accent' => ['required', 'string', 'max:7'],
            'heading_font' => ['required', Rule::in(array_keys(Theme::FONTS))],
            'body_font' => ['required', Rule::in(array_keys(Theme::FONTS))],
            'density' => ['required', Rule::in(array_keys(Theme::DENSITIES))],
            'card_ratings' => ['required', 'boolean'],
            'card_compare' => ['required', 'boolean'],
            'card_badges' => ['required', 'boolean'],
            'logo_media_id' => ['nullable', Rule::exists('media', 'id')->where('type', 'image')],
            'favicon_media_id' => ['nullable', Rule::exists('media', 'id')->where('type', 'image')],
            'socials' => ['array', 'max:'.count(Theme::SOCIALS)],
            'socials.*.network' => ['required', 'distinct', Rule::in(array_keys(Theme::SOCIALS))],
            'socials.*.url' => ['required', 'string', 'max:300', 'regex:~^https://~i'],
        ], [
            'socials.*.url.required' => 'Enter the link, or remove this row.',
            'socials.*.url.regex' => 'A social link starts with https://',
            'socials.*.network.distinct' => 'Each network can be listed once.',
        ]);

        $accent = Theme::hex($data['accent']);
        if ($accent === null) {
            throw ValidationException::withMessages(['accent' => 'Enter a colour as six letters or numbers, like #1D4ED8.']);
        }
        // A custom colour is checked the same way the tested set was: white text on it must stay readable.
        if (! Theme::accentPasses($accent)) {
            throw ValidationException::withMessages(['accent' => sprintf(
                'This colour is too light: white text on it has a contrast of %.1f to 1 and it needs 4.5 to 1. Choose a darker shade.',
                Theme::contrast($accent, '#FFFFFF'),
            )]);
        }

        $before = $settings->theme();
        Setting::put('theme', ['accent' => $accent, 'socials' => array_values($data['socials'] ?? [])] + $data);
        $activity->log('edit', 'theme', null, 'Theme', array_filter([
            'accent' => $before['accent'] !== $accent ? [$before['accent'], $accent] : null,
            'heading_font' => $before['heading_font'] !== $data['heading_font'] ? [$before['heading_font'], $data['heading_font']] : null,
            'body_font' => $before['body_font'] !== $data['body_font'] ? [$before['body_font'], $data['body_font']] : null,
        ]));

        return back()->with('success', 'Theme saved. The storefront uses it now.');
    }
}
