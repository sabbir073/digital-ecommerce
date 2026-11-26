<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\ActivityLogger;
use App\Services\Seo\SeoSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Site-wide SEO: templates, share image, verification, custom code, robots.txt and the sitemap (PRD section 13). */
class SeoSettingsController extends Controller
{
    public function __construct(private readonly SeoSettings $settings, private readonly ActivityLogger $activity) {}

    public function edit(): Response
    {
        $all = $this->settings->all();
        $share = $all['share_media_id'] ? Media::query()->find($all['share_media_id']) : null;

        return Inertia::render('Seo/Settings', [
            'settings' => $all,
            'shareImage' => $share?->toAdminArray(),
            'types' => collect(SeoSettings::TYPES)->map(fn (string $label, string $type) => [
                'type' => $type,
                'label' => $label,
                'variables' => SeoSettings::VARIABLES[$type],
                'defaults' => SeoSettings::TEMPLATES[$type],
            ])->values(),
            'sitemaps' => SeoSettings::SITEMAPS,
            'sitemapUrl' => url('/sitemap.xml'),
            'robotsUrl' => url('/robots.txt'),
            'defaultRobots' => SeoSettings::DEFAULT_ROBOTS,
            'robotsIsDefault' => $all['robots'] === SeoSettings::DEFAULT_ROBOTS,
            'canEditCode' => (bool) Auth::guard('admin')->user()?->isSuper(),
        ]);
    }

    public function updateTemplates(Request $request): RedirectResponse
    {
        $rules = ['share_media_id' => ['nullable', Rule::exists('media', 'id')->where('type', 'image')]];
        foreach (array_keys(SeoSettings::TYPES) as $type) {
            $rules["templates.$type.title"] = ['required', 'string', 'max:255'];
            $rules["templates.$type.description"] = ['nullable', 'string', 'max:500'];
        }
        $data = $request->validate($rules, ['templates.*.title.required' => 'A title template cannot be empty.']);
        foreach ($data['templates'] as $type => $template) {
            $data['templates'][$type]['description'] = (string) ($template['description'] ?? '');
        }

        $this->settings->save(['templates' => $data['templates'], 'share_media_id' => $data['share_media_id'] ?? null]);
        $this->activity->log('edit', 'seo', null, 'SEO templates and share image');

        return back()->with('success', 'Templates saved.');
    }

    public function updateVerification(Request $request): RedirectResponse
    {
        $data = $request->validate(['verification' => ['nullable', 'string', 'max:5000']]);
        $given = trim((string) ($data['verification'] ?? ''));
        $clean = SeoSettings::cleanTags($given);
        if ($given !== '' && $clean === '') {
            return back()->withErrors(['verification' => 'Paste the whole tag, for example <meta name="google-site-verification" content="…">.']);
        }

        $this->settings->save(['verification' => $clean]);
        $this->activity->log('edit', 'seo', null, 'Site verification tags');

        return back()->with('success', 'Verification tags saved.');
    }

    /** Code that runs on every storefront page. Reserved for Super Admins in code, not in the permission matrix. */
    public function updateCode(Request $request): RedirectResponse
    {
        abort_unless(Auth::guard('admin')->user()?->isSuper(), 403);

        $data = $request->validate([
            'head' => ['nullable', 'string', 'max:20000'],
            'body_start' => ['nullable', 'string', 'max:20000'],
            'body_end' => ['nullable', 'string', 'max:20000'],
        ]);

        $this->settings->save(array_map(fn ($value) => trim((string) $value), $data + ['head' => '', 'body_start' => '', 'body_end' => '']));
        $this->activity->log('edit', 'seo', null, 'Site-wide custom code');

        return back()->with('success', 'Custom code saved. It is now on every storefront page.');
    }

    public function updateRobots(Request $request): RedirectResponse
    {
        $data = $request->validate(['robots' => ['required', 'string', 'max:10000']], ['robots.required' => 'robots.txt cannot be empty. Use Reset to go back to the safe default.']);
        if (! preg_match('/^\s*User-agent\s*:/mi', $data['robots'])) {
            return back()->withErrors(['robots' => 'robots.txt needs at least one "User-agent:" line.']);
        }

        $this->settings->save(['robots' => trim(str_replace("\r\n", "\n", $data['robots']))]);
        $this->activity->log('edit', 'seo', null, 'robots.txt');

        return back()->with('success', 'robots.txt saved.');
    }

    public function resetRobots(): RedirectResponse
    {
        $this->settings->save(['robots' => '']);
        $this->activity->log('edit', 'seo', null, 'robots.txt reset to the default');

        return back()->with('success', 'robots.txt is back to the safe default.');
    }

    public function updateSitemap(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sitemap_exclude' => ['array'],
            'sitemap_exclude.*' => [Rule::in(array_keys(SeoSettings::SITEMAPS))],
        ]);

        $this->settings->save(['sitemap_exclude' => array_values($data['sitemap_exclude'] ?? [])]);
        $this->activity->log('edit', 'seo', null, 'Sitemap content types');

        return back()->with('success', 'Sitemap saved.');
    }
}
