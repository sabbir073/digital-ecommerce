<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentPage;
use App\Models\Media;
use App\Services\ActivityLogger;
use App\Services\Selling\PagePresenter;
use App\Support\Blocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Content pages (About, Contact, policies): made with the block editor, without the blocks that sell. */
class ContentPageController extends Controller
{
    private const FOR = 'content';

    public function __construct(private readonly ActivityLogger $activity, private readonly PagePresenter $presenter) {}

    public function index(): Response
    {
        return Inertia::render('Content/Pages', [
            'pages' => ContentPage::query()->orderBy('title')->get()->map(fn (ContentPage $page) => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'status' => $page->status,
                'url' => $page->url(),
                'updatedAt' => $page->updated_at?->toIso8601String(),
                'hasUnpublishedChanges' => $page->isPublished() && $page->draft_blocks != $page->published_blocks,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:120']]);

        $page = ContentPage::create([
            'title' => $data['title'],
            'slug' => $this->uniqueSlug($data['title']),
            'status' => ContentPage::DRAFT,
            'draft_blocks' => [Blocks::make('rich_text', [], [], self::FOR)],
        ]);
        $this->activity->log('create', 'content', $page, $page->title);

        return redirect()->route('admin.pages.builder', $page)->with('success', 'Page created as a draft. Add its content, then publish.');
    }

    public function update(Request $request, ContentPage $page): RedirectResponse
    {
        $request->merge(['slug' => Str::slug((string) $request->input('slug'))]);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', Rule::unique('content_pages', 'slug')->ignore($page->id)],
        ], ['slug.required' => 'Enter the page address, using letters, numbers and dashes.', 'slug.unique' => 'Another page already uses this address.']);

        $page->update($data);
        $this->activity->log('edit', 'content', $page, $page->title);

        return back()->with('success', 'Saved.');
    }

    public function builder(ContentPage $page): Response
    {
        $blocks = Blocks::normalize($page->draft_blocks ?? [], self::FOR);

        return Inertia::render('SellingPages/Builder', [
            'page' => [
                'id' => $page->id,
                'name' => $page->title,
                'status' => $page->status,
                'url' => $page->url(),
                'previewUrl' => $page->url().'?preview=1',
                'canvasUrl' => route('admin.pages.canvas', $page),
                'hasUnpublishedChanges' => $this->changed($page, $blocks),
                'base' => '/admin/pages/'.$page->id,
                'backUrl' => '/admin/pages',
                'module' => 'content',
            ],
            'blocks' => $blocks,
            'library' => Blocks::library(self::FOR),
            'media' => $this->media($blocks),
            'reviews' => [],
            'problems' => Blocks::problems($blocks, self::FOR),
            'versions' => null,
        ]);
    }

    public function canvas(ContentPage $page): HttpResponse
    {
        $blocks = Blocks::normalize($page->draft_blocks ?? [], self::FOR);

        return response()->view('storefront.page', $this->viewData($page, $blocks))->header('Cache-Control', 'no-store, private');
    }

    public function render(Request $request, ContentPage $page): JsonResponse
    {
        $request->validate(['blocks' => ['present', 'array', 'max:60']]);
        $blocks = Blocks::normalize($request->input('blocks'), self::FOR);

        return response()->json(['html' => view('selling._blocks', $this->viewData($page, $blocks))->render()]);
    }

    public function saveDraft(Request $request, ContentPage $page): JsonResponse
    {
        $request->validate(['blocks' => ['present', 'array', 'max:60']]);
        $blocks = Blocks::normalize($request->input('blocks'), self::FOR);
        $page->update(['draft_blocks' => $blocks]);

        return response()->json(['problems' => Blocks::problems($blocks, self::FOR), 'hasUnpublishedChanges' => $this->changed($page, $blocks)]);
    }

    public function publish(ContentPage $page): RedirectResponse
    {
        $blocks = Blocks::normalize($page->draft_blocks ?? [], self::FOR);
        $problems = Blocks::problems($blocks, self::FOR);
        if ($problems !== []) {
            return back()->with('error', 'This page cannot be published yet. '.implode(' ', $problems));
        }

        $page->update(['status' => ContentPage::PUBLISHED, 'published_blocks' => $blocks, 'published_at' => now()]);
        $this->activity->log('publish', 'content', $page, $page->title);

        return back()->with('success', 'Published. The page is live.');
    }

    public function unpublish(ContentPage $page): RedirectResponse
    {
        $page->update(['status' => ContentPage::DRAFT]);
        $this->activity->log('publish', 'content', $page, $page->title, ['status' => ['published', 'draft']]);

        return back()->with('success', 'Unpublished. Menu links to this page are hidden until it is published again.');
    }

    public function destroy(ContentPage $page): RedirectResponse
    {
        $this->activity->log('delete', 'content', $page, $page->title);
        $page->delete();

        return redirect()->route('admin.pages.index')->with('success', "{$page->title} deleted.");
    }

    /** @param list<array<string, mixed>> $blocks */
    private function changed(ContentPage $page, array $blocks): bool
    {
        return $page->isPublished() && $blocks != Blocks::normalize($page->published_blocks, self::FOR);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function viewData(ContentPage $page, array $blocks): array
    {
        return $this->presenter->present(null, $blocks, builder: true) + ['page' => $page, 'builder' => true, 'preview' => false, 'blocksFor' => self::FOR];
    }

    /** @param list<array<string, mixed>> $blocks */
    private function media(array $blocks)
    {
        $ids = [];
        foreach ($blocks as $block) {
            foreach (['image_id', 'poster_id', 'video_id'] as $key) {
                if (! empty($block['data'][$key])) {
                    $ids[] = $block['data'][$key];
                }
            }
            array_push($ids, ...($block['data']['image_ids'] ?? []));
        }

        return Media::query()->whereIn('id', array_unique($ids))->get()->map->toAdminArray()->keyBy('id');
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'page';
        $slug = $base;
        $i = 2;
        while (ContentPage::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
