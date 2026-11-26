<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Integrations\Tracking;
use App\Services\Seo\SeoBuilder;
use App\Services\Seo\StructuredData;
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
            'trackEvents' => array_values(array_filter([
                $search !== null && trim($search) !== '' ? Tracking::search($search) : null,
                Tracking::viewItemList($result['products']->items(), $title),
            ])),
            'seo' => $this->seo($request, $title, $category, $search, $result['products']->currentPage()),
        ]);
    }

    /**
     * Lists follow three rules: a search is never indexed; a filtered or sorted list is not indexed and
     * points at the clean address; page 2 and later keep their own address so they are not folded into page 1.
     *
     * @return array<string, mixed>
     */
    private function seo(Request $request, string $title, ?Category $category, ?string $search, int $page): array
    {
        $builder = app(SeoBuilder::class);
        $filtered = collect($request->query())->except(['page'])->filter(fn ($value) => $value !== null && $value !== '' && $value !== [])->isNotEmpty();
        $clean = $request->url();
        $options = [
            'canonical' => $filtered || $page < 2 ? $clean : $clean.'?page='.$page,
            'noindex' => $filtered || $search !== null,
        ];

        if ($category === null) {
            return $builder->page($title, '', $options);
        }

        $trail = [['name' => t('Home'), 'url' => route('home')]];
        if ($category->parent) {
            $trail[] = ['name' => $category->parent->name, 'url' => url('/c/'.$category->parent->slug)];
        }
        $trail[] = ['name' => $category->name, 'url' => url('/c/'.$category->slug)];
        // The owner's own canonical for the category wins on its first, unfiltered page only.
        if (! $filtered && $page < 2) {
            unset($options['canonical']);
        }

        return $builder->for('category', $category, $options + ['json_ld' => [app(StructuredData::class)->breadcrumbs($trail)]]);
    }
}
