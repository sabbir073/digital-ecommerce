<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use App\Models\SellingPage;
use App\Models\SeoMeta;
use App\Models\Setting;
use App\Services\Seo\SeoSettings;
use App\Services\Seo\SeoSubjects;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/** robots.txt and the XML sitemap. Both are built when asked for, so they are never out of date. */
class SeoFilesController extends Controller
{
    public function __construct(private readonly SeoSettings $settings) {}

    public function robots(): Response
    {
        return response($this->settings->robotsTxt(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** The index: one sitemap per content type, minus the types the owner left out. */
    public function index(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($this->included() as $name) {
            $xml .= '  <sitemap><loc>'.e(url("/sitemap-{$name}.xml")).'</loc></sitemap>'."\n";
        }

        return $this->xml($xml.'</sitemapindex>'."\n");
    }

    public function show(string $name): Response
    {
        abort_unless(in_array($name, $this->included(), true), 404);

        $urls = match ($name) {
            'products' => $this->entries('product', Product::query()->visible()->orderBy('id')->get(['id', 'slug', 'updated_at']), fn ($p) => route('product', $p->slug)),
            'categories' => $this->entries('category', Category::query()->orderBy('id')->get(['id', 'slug', 'updated_at']), fn ($c) => url('/c/'.$c->slug)),
            'selling-pages' => $this->entries('selling_page', SellingPage::query()->published()->orderBy('id')->get(['id', 'slug', 'updated_at', 'published_at']), fn ($p) => $p->url()),
            'pages' => array_merge(
                $this->homeEntry(),
                $this->entries('page', ContentPage::query()->published()->orderBy('id')->get(['id', 'slug', 'updated_at', 'published_at']), fn ($p) => $p->url()),
            ),
            'posts' => array_merge(
                BlogPost::query()->live()->exists() ? [['loc' => route('blog.index'), 'lastmod' => BlogPost::query()->live()->max('updated_at')]] : [],
                $this->entries('post', BlogPost::query()->live()->orderBy('id')->get(['id', 'slug', 'updated_at', 'published_at']), fn ($p) => $p->url()),
            ),
        };

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url['loc']).'</loc>'.($url['lastmod'] ? '<lastmod>'.Carbon::parse($url['lastmod'])->toAtomString().'</lastmod>' : '').'</url>'."\n";
        }

        return $this->xml($xml.'</urlset>'."\n");
    }

    /** @return list<string> */
    private function included(): array
    {
        return array_values(array_diff(array_keys(SeoSettings::SITEMAPS), $this->settings->all()['sitemap_exclude']));
    }

    /**
     * Public items of one type, without the ones set to noindex.
     *
     * @return list<array{loc:string, lastmod:mixed}>
     */
    private function entries(string $type, $items, callable $url): array
    {
        $hidden = SeoMeta::query()->where('seoable_type', SeoSubjects::MODELS[$type])->where('index', false)->pluck('seoable_id')->flip();

        return $items->reject(fn ($item) => $hidden->has($item->id))
            ->map(fn ($item) => ['loc' => $url($item), 'lastmod' => $item->updated_at])->values()->all();
    }

    /** @return list<array{loc:string, lastmod:mixed}> */
    private function homeEntry(): array
    {
        return ((array) Setting::get('seo.home', []))['index'] ?? true ? [['loc' => url('/'), 'lastmod' => null]] : [];
    }

    private function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
