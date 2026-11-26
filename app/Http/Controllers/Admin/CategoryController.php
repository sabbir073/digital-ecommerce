<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        $map = fn (Category $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'parentId' => $c->parent_id,
            'position' => $c->position,
            'products' => $c->products_count,
            'image' => $c->image?->toAdminArray(),
        ];

        return Inertia::render('Categories/Index', [
            'categories' => Category::query()
                ->whereNull('parent_id')
                ->with(['image', 'children' => fn ($q) => $q->with('image')->withCount('products')])
                ->withCount('products')
                ->orderBy('position')->orderBy('name')
                ->get()
                ->map(fn (Category $c) => [...$map($c), 'children' => $c->children->map($map)->values()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['position'] = (int) Category::query()->where('parent_id', $data['parent_id'] ?? null)->max('position') + 1;
        $category = Category::create($data);
        $this->activity->log('create', 'categories', $category, $category->name);

        return back()->with('success', "Category {$category->name} created.");
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validated($request, $category);

        if (($data['parent_id'] ?? null) !== null && $category->children()->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'This category has sub-categories, so it must stay at the top level.']);
        }

        $category->update($data);
        $this->activity->log('edit', 'categories', $category, $category->name);

        return back()->with('success', "Category {$category->name} saved.");
    }

    /** Save a new order for the categories that share one parent. */
    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', Rule::exists('categories', 'id')],
        ]);

        foreach (array_values($data['ids']) as $position => $id) {
            Category::query()->whereKey($id)->update(['position' => $position]);
        }
        $this->activity->log('edit', 'categories', null, 'Category order');

        return back();
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->children()->exists()) {
            throw ValidationException::withMessages(['category' => 'Delete or move its sub-categories first.']);
        }

        $name = $category->name;
        $this->activity->log('delete', 'categories', $category, $name);
        $category->delete(); // products keep existing, without a category

        return back()->with('success', "Category {$name} deleted.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('parent_id'), Rule::notIn([$category?->id])],
            'media_id' => ['nullable', 'integer', Rule::exists('media', 'id')],
        ], [
            'slug.regex' => 'Use lower-case letters, numbers and hyphens only.',
            'parent_id.exists' => 'Categories can be two levels deep at most.',
        ]);

        $data['parent_id'] ??= null;
        $data['media_id'] ??= null;

        return $data;
    }
}
