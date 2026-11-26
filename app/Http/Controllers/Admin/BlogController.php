<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Services\ActivityLogger;
use App\Support\HtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BlogController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), [BlogPost::DRAFT, BlogPost::PUBLISHED], true) ? (string) $request->query('status') : '';

        return Inertia::render('Blog/Index', [
            'posts' => BlogPost::query()->with(['category:id,name', 'author:id,name'])
                ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->orderByRaw('COALESCE(published_at, created_at) DESC')->paginate(25)->withQueryString()
                ->through(fn (BlogPost $post) => [
                    'id' => $post->id,
                    'title' => $post->title,
                    'status' => $post->status,
                    'scheduled' => $post->status === BlogPost::PUBLISHED && ! $post->isLive(),
                    'category' => $post->category?->name,
                    'author' => $post->author?->name,
                    'publishedAt' => $post->published_at?->toIso8601String(),
                    'url' => $post->url(),
                ]),
            'filters' => ['search' => $search, 'status' => $status],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Blog/Edit', ['post' => null] + $this->options());
    }

    public function edit(BlogPost $post): Response
    {
        $post->load('cover');

        return Inertia::render('Blog/Edit', [
            'post' => [
                'id' => $post->id,
                'title' => $post->title,
                'slug' => $post->slug,
                'excerpt' => $post->excerpt ?? '',
                'body' => $post->body ?? '',
                'category' => $post->category?->name ?? '',
                'tags' => $post->tags ?? [],
                'author_id' => $post->author_id,
                'status' => $post->status,
                'published_at' => $post->published_at?->toIso8601String(),
                'cover' => $post->cover?->toAdminArray(),
                'url' => $post->url(),
                'live' => $post->isLive(),
            ],
        ] + $this->options());
    }

    public function store(Request $request): RedirectResponse
    {
        $post = BlogPost::create($this->validated($request, null));
        $this->activity->log('create', 'blog', $post, $post->title);

        return redirect()->route('admin.blog.edit', $post)->with('success', $post->status === BlogPost::PUBLISHED ? 'Post published.' : 'Draft saved.');
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $was = $post->status;
        $post->update($this->validated($request, $post));
        $this->activity->log($was !== $post->status ? 'publish' : 'edit', 'blog', $post, $post->title, $was !== $post->status ? ['status' => [$was, $post->status]] : []);

        return back()->with('success', 'Saved.');
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        $this->activity->log('delete', 'blog', $post, $post->title);
        $post->delete();
        BlogCategory::query()->whereDoesntHave('posts')->delete();

        return redirect()->route('admin.blog.index')->with('success', 'Post deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?BlogPost $post): array
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('title')))]);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:160', Rule::unique('blog_posts', 'slug')->ignore($post?->id)],
            'excerpt' => ['nullable', 'string', 'max:300'],
            'body' => ['nullable', 'string', 'max:100000'],
            'category' => ['nullable', 'string', 'max:60'],
            'tags' => ['array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
            'author_id' => ['nullable', Rule::exists('admin_users', 'id')],
            'status' => ['required', Rule::in([BlogPost::DRAFT, BlogPost::PUBLISHED])],
            'published_at' => ['nullable', 'date'],
            'cover_media_id' => ['nullable', Rule::exists('media', 'id')->where('type', 'image')],
        ], ['slug.required' => 'Enter a title.', 'slug.unique' => 'Another post already uses this address.']);

        // Publishing needs the "publish" permission; without it a post can only be saved as it was.
        $publishing = $data['status'] === BlogPost::PUBLISHED && $post?->status !== BlogPost::PUBLISHED;
        $unpublishing = $data['status'] === BlogPost::DRAFT && $post?->status === BlogPost::PUBLISHED;
        if (($publishing || $unpublishing) && ! Auth::guard('admin')->user()->allows('blog', 'publish')) {
            abort(403, 'Your role cannot publish or unpublish posts.');
        }

        $category = trim((string) ($data['category'] ?? ''));

        return [
            'title' => $data['title'],
            'slug' => $data['slug'],
            'excerpt' => $data['excerpt'] ?? null,
            'body' => HtmlSanitizer::clean($data['body'] ?? ''),
            'blog_category_id' => $category !== '' ? BlogCategory::query()->firstOrCreate(['slug' => Str::slug($category) ?: Str::random(8)], ['name' => $category])->id : null,
            'tags' => array_values(array_unique(array_filter(array_map('trim', $data['tags'] ?? [])))),
            'author_id' => $data['author_id'] ?? Auth::guard('admin')->id(),
            'status' => $data['status'],
            // A post published without a date goes live now; a later date schedules it.
            'published_at' => $data['status'] === BlogPost::PUBLISHED
                ? (($data['published_at'] ?? null) ? Carbon::parse($data['published_at']) : ($post?->published_at ?? now()))
                : (($data['published_at'] ?? null) ? Carbon::parse($data['published_at']) : null),
            'cover_media_id' => $data['cover_media_id'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'categories' => BlogCategory::query()->orderBy('name')->pluck('name'),
            'authors' => AdminUser::query()->where('status', AdminUser::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name'])->map(fn ($a) => ['id' => $a->id, 'name' => $a->name]),
            'me' => Auth::guard('admin')->id(),
        ];
    }
}
