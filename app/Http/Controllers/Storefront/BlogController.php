<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BlogController extends Controller
{
    public function index(Request $request, ?string $category = null): View
    {
        $current = $category !== null ? BlogCategory::query()->where('slug', $category)->firstOrFail() : null;
        $tag = trim((string) $request->query('tag', ''));

        $posts = BlogPost::query()->live()->with(['cover', 'category', 'author:id,name'])
            ->when($current, fn ($q) => $q->where('blog_category_id', $current->id))
            ->when($tag !== '', fn ($q) => $q->whereJsonContains('tags', $tag))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(9)->withQueryString();

        return view('storefront.blog.index', [
            'posts' => $posts,
            'current' => $current,
            'tag' => $tag,
            'categories' => BlogCategory::query()->whereHas('posts', fn ($q) => $q->live())->orderBy('name')->get(),
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $post = BlogPost::query()->where('slug', $slug)->with(['cover', 'category', 'author:id,name'])->firstOrFail();

        // Staff who may see the blog can read a draft or scheduled post before visitors do.
        $preview = ! $post->isLive() && Auth::guard('admin')->user()?->allows('blog', 'view');
        abort_unless($post->isLive() || $preview, 404);

        return view('storefront.blog.show', [
            'post' => $post,
            'preview' => $preview,
            'more' => BlogPost::query()->live()->with(['cover', 'category'])->whereKeyNot($post->id)
                ->when($post->blog_category_id, fn ($q) => $q->orderByRaw('CASE WHEN blog_category_id = ? THEN 0 ELSE 1 END', [$post->blog_category_id]))
                ->orderByDesc('published_at')->limit(3)->get(),
        ]);
    }
}
