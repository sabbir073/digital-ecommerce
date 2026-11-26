<?php

namespace App\Services\Seo;

use App\Models\Setting;

/** Site-wide SEO settings with their defaults (PRD section 13). */
class SeoSettings
{
    public const TYPES = [
        'home' => 'Home page',
        'product' => 'Products',
        'category' => 'Categories',
        'selling_page' => 'Selling pages',
        'page' => 'Pages',
        'post' => 'Blog posts',
    ];

    /** The sitemap files, by content type. */
    public const SITEMAPS = ['products' => 'Products', 'categories' => 'Categories', 'selling-pages' => 'Selling pages', 'pages' => 'Pages', 'posts' => 'Blog posts'];

    public const TEMPLATES = [
        'home' => ['title' => '{{store_name}}', 'description' => '{{tagline}}'],
        'product' => ['title' => '{{product_name}} · {{store_name}}', 'description' => '{{summary}}'],
        'category' => ['title' => '{{category_name}} · {{store_name}}', 'description' => 'Shop {{category_name}} at {{store_name}}.'],
        'selling_page' => ['title' => '{{page_name}} · {{store_name}}', 'description' => '{{summary}}'],
        'page' => ['title' => '{{page_title}} · {{store_name}}', 'description' => '{{summary}}'],
        'post' => ['title' => '{{post_title}} · {{store_name}}', 'description' => '{{summary}}'],
    ];

    /** The words each template can use. */
    public const VARIABLES = [
        'home' => ['store_name' => 'The store name', 'tagline' => 'The tagline from Settings, Store'],
        'product' => ['product_name' => 'The product name', 'category_name' => 'Its category', 'price' => 'Its price', 'summary' => 'Its short description', 'store_name' => 'The store name'],
        'category' => ['category_name' => 'The category name', 'store_name' => 'The store name'],
        'selling_page' => ['page_name' => 'The page name', 'product_name' => 'The product it sells', 'price' => 'The lowest pack price', 'summary' => 'The product\'s short description', 'store_name' => 'The store name'],
        'page' => ['page_title' => 'The page title', 'summary' => 'The first lines of the page', 'store_name' => 'The store name'],
        'post' => ['post_title' => 'The post title', 'category_name' => 'Its category', 'summary' => 'The excerpt', 'store_name' => 'The store name'],
    ];

    public const DEFAULT_ROBOTS = "User-agent: *\nDisallow: /admin\nDisallow: /cart\nDisallow: /checkout\nDisallow: /account\nDisallow: /order/\nDisallow: /download/\nDisallow: /search\nDisallow: /login\nDisallow: /register";

    /** @return array{templates: array<string, array{title:string, description:string}>, share_media_id:?int, verification:string, head:string, body_start:string, body_end:string, robots:string, sitemap_exclude:list<string>} */
    public function all(): array
    {
        $saved = (array) Setting::get('seo.settings', []);
        $templates = [];
        foreach (self::TEMPLATES as $type => $defaults) {
            $templates[$type] = [
                'title' => trim((string) ($saved['templates'][$type]['title'] ?? '')) ?: $defaults['title'],
                'description' => (string) ($saved['templates'][$type]['description'] ?? $defaults['description']),
            ];
        }

        return [
            'templates' => $templates,
            'share_media_id' => isset($saved['share_media_id']) ? (int) $saved['share_media_id'] : null,
            'verification' => (string) ($saved['verification'] ?? ''),
            'head' => (string) ($saved['head'] ?? ''),
            'body_start' => (string) ($saved['body_start'] ?? ''),
            'body_end' => (string) ($saved['body_end'] ?? ''),
            'robots' => trim((string) ($saved['robots'] ?? '')) ?: self::DEFAULT_ROBOTS,
            'sitemap_exclude' => array_values(array_intersect((array) ($saved['sitemap_exclude'] ?? []), array_keys(self::SITEMAPS))),
        ];
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        Setting::put('seo.settings', array_merge((array) Setting::get('seo.settings', []), $values));
    }

    /** The robots.txt the store serves: the owner's rules, then the sitemap address, which always matches this site. */
    public function robotsTxt(): string
    {
        $rules = preg_replace('/^\s*Sitemap:.*$/mi', '', $this->all()['robots']);

        return trim((string) $rules)."\n\nSitemap: ".url('/sitemap.xml')."\n";
    }

    /**
     * Only <meta> and <link> tags survive, and none that could run code or send the visitor away.
     * Used for verification tags and for the custom tags of one page.
     */
    public static function cleanTags(?string $html): string
    {
        preg_match_all('/<(meta|link)\b[^<>]*>/i', (string) $html, $matches);
        $safe = array_filter($matches[0], fn (string $tag) => ! preg_match('/\bon[a-z]+\s*=|javascript:|http-equiv\s*=\s*["\']?\s*refresh|rel\s*=\s*["\']?\s*(stylesheet|preload|modulepreload|prefetch)/i', $tag));

        return implode("\n", array_map(fn (string $tag) => rtrim(rtrim($tag, '>'), '/ ').'>', $safe));
    }
}
