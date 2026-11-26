<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\ActivityLogger;
use App\Services\Seo\SeoBuilder;
use App\Services\Seo\SeoChecklist;
use App\Services\Seo\SeoSettings;
use App\Services\Seo\SeoSubjects;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The SEO dashboard and the SEO panel of each item (PRD section 13). */
class SeoController extends Controller
{
    private const SINGULAR = ['home' => 'Home page', 'product' => 'Product', 'category' => 'Category', 'selling_page' => 'Selling page', 'page' => 'Page', 'post' => 'Blog post'];

    public function __construct(
        private readonly SeoSubjects $subjects,
        private readonly SeoBuilder $builder,
        private readonly SeoChecklist $checklist,
        private readonly ActivityLogger $activity,
    ) {}

    /** Every item whose checklist has a failing line, so they can be fixed one by one. */
    public function dashboard(Request $request): Response
    {
        $filter = isset(self::SINGULAR[$request->query('type')]) ? (string) $request->query('type') : '';
        $counts = array_fill_keys(array_keys(self::SINGULAR), ['total' => 0, 'failing' => 0]);
        $failing = [];

        foreach ($this->subjects->all() as [$type, $model]) {
            $counts[$type]['total']++;
            $problems = array_values(array_filter($this->checklist->run($type, $model), fn (array $check) => ! $check['ok']));
            if ($problems === []) {
                continue;
            }
            $counts[$type]['failing']++;
            if ($filter !== '' && $filter !== $type) {
                continue;
            }
            $facts = $this->subjects->facts($type, $model);
            $failing[] = [
                'type' => $type,
                'typeLabel' => self::SINGULAR[$type],
                'id' => $model?->getKey() ?? 0,
                'name' => $facts['name'],
                'public' => SeoSubjects::isPublic($type, $model),
                'problems' => array_map(fn (array $check) => ['label' => $check['label'], 'hint' => $check['hint']], $problems),
            ];
        }

        return Inertia::render('Seo/Dashboard', [
            'items' => array_slice($failing, 0, 500),
            'counts' => collect($counts)->map(fn (array $count, string $type) => ['type' => $type, 'label' => SeoSettings::TYPES[$type]] + $count)->values(),
            'filter' => $filter,
        ]);
    }

    public function edit(string $type, int $id = 0): Response
    {
        $model = $this->subject($type, $id);
        $meta = $this->subjects->meta($type, $model);
        $facts = $this->subjects->facts($type, $model);
        $defaults = $this->builder->resolve($type, array_merge($meta, ['meta_title' => '', 'meta_description' => '']), $facts);
        $share = $meta['og_media_id'] ? Media::query()->find($meta['og_media_id']) : null;

        return Inertia::render('Seo/Edit', [
            'item' => [
                'type' => $type,
                'typeLabel' => self::SINGULAR[$type],
                'id' => $id,
                'name' => $facts['name'],
                'url' => $facts['url'],
                'public' => SeoSubjects::isPublic($type, $model),
                'slug' => $model?->slug,
                'pathPrefix' => $type === 'home' ? null : url(rtrim(SeoSubjects::path($type, ''), '/')).'/',
                'editUrl' => $this->editUrl($type, $id),
            ],
            'meta' => $meta,
            'defaults' => [
                'title' => $defaults['title'],
                'description' => $defaults['description'],
                'canonical' => $facts['url'],
                'image' => ($facts['image'] ?? null)?->sizeUrl(640),
            ],
            'shareImage' => $share?->toAdminArray(),
            'checks' => $this->checklist->run($type, $model, $meta),
            'limits' => ['title' => SeoChecklist::TITLE_MAX, 'description' => SeoChecklist::DESCRIPTION_MAX],
            'host' => parse_url(url('/'), PHP_URL_HOST),
        ]);
    }

    /** The checklist for what is in the panel right now, before it is saved. */
    public function check(Request $request, string $type, int $id = 0): JsonResponse
    {
        $model = $this->subject($type, $id);
        $meta = array_merge($this->subjects->meta($type, $model), $request->only(['meta_title', 'meta_description', 'focus_keyword']));

        return response()->json(['checks' => $this->checklist->run($type, $model, $meta)]);
    }

    public function update(Request $request, string $type, int $id = 0): RedirectResponse
    {
        $model = $this->subject($type, $id);
        if ($model) {
            $request->merge(['slug' => Str::slug((string) $request->input('slug'))]);
        }

        $data = $request->validate([
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'url:http,https', 'max:255'],
            'index' => ['required', 'boolean'],
            'follow' => ['required', 'boolean'],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string', 'max:500'],
            'og_media_id' => ['nullable', Rule::exists('media', 'id')->where('type', 'image')],
            'focus_keyword' => ['nullable', 'string', 'max:100'],
            'custom_tags' => ['nullable', 'string', 'max:5000'],
            'slug' => $model ? ['required', 'string', 'max:160', Rule::unique($model->getTable(), 'slug')->ignore($model->getKey())] : ['nullable'],
        ], [
            'canonical_url.url' => 'Enter the full address, starting with https://',
            'slug.required' => 'Enter the address, using letters, numbers and dashes.',
            'slug.unique' => 'Another '.Str::lower(self::SINGULAR[$type]).' already uses this address.',
        ]);

        $moved = false;
        if ($model && $data['slug'] !== $model->slug) {
            $moved = SeoSubjects::isPublic($type, $model);
            // The model redirects its old address to the new one when it is public.
            $model->update(['slug' => $data['slug']]);
        }
        $this->subjects->saveMeta($type, $model, $data);
        $this->activity->log('edit', 'seo', $model, 'SEO: '.$this->subjects->facts($type, $model)['name']);

        return redirect()->route('admin.seo.edit', $model ? ['type' => $type, 'id' => $id] : ['type' => $type])
            ->with('success', $moved ? 'Saved. The old address now redirects to the new one.' : 'SEO saved.');
    }

    private function subject(string $type, int $id): ?Model
    {
        abort_unless(isset(self::SINGULAR[$type]), 404);
        if ($type === 'home') {
            return null;
        }
        $model = $this->subjects->find($type, $id);
        abort_unless($model, 404);

        return $model;
    }

    private function editUrl(string $type, int $id): string
    {
        return match ($type) {
            'product' => "/admin/products/{$id}",
            'category' => '/admin/categories',
            'selling_page' => "/admin/selling-pages/{$id}",
            'page' => '/admin/pages',
            'post' => "/admin/blog/{$id}",
            default => '/admin/home',
        };
    }
}
