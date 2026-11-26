<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Storefront\CatalogQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(private readonly CatalogQuery $catalog) {}

    public function index(Request $request): View
    {
        return $this->listing($request, title: 'All products');
    }

    public function category(Request $request, string $slug): View
    {
        $category = Category::query()->where('slug', $slug)->with(['parent', 'children'])->firstOrFail();

        return $this->listing($request, title: $category->name, category: $category);
    }

    public function search(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));
        $term = mb_substr($term, 0, 100);

        return $this->listing($request, title: $term === '' ? 'Search' : "Results for \u{201C}{$term}\u{201D}", search: $term);
    }

    private function listing(Request $request, string $title, ?Category $category = null, ?string $search = null): View
    {
        $result = $this->catalog->run($request->query(), $category, $search);

        return view('storefront.catalog', [
            'title' => $title,
            'category' => $category,
            'search' => $search,
            'products' => $result['products'],
            'filters' => $result['filters'],
            'sorts' => CatalogQuery::SORTS,
            'allCategories' => Category::query()->whereNull('parent_id')->with('children')->orderBy('position')->orderBy('name')->get(),
            'action' => $request->url(),
        ]);
    }
}
