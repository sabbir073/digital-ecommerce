<?php

namespace App\Services\Seo;

use App\Models\Media;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Builds what goes in the <head> of a storefront page: the item's own SEO fields where the owner
 * filled them in, the site-wide templates and defaults where they did not.
 */
class SeoBuilder
{
    public function __construct(private readonly SeoSettings $settings, private readonly SeoSubjects $subjects) {}

    /**
     * @param  array{canonical?:?string, noindex?:bool, json_ld?:list<array<string, mixed>>}  $options  what the page itself decides:
     *                                                                                                  a paginated or filtered list sets its own canonical and noindex
     * @return array{title:string, description:string, canonical:string, robots:?string, og:array<string, ?string>, custom_tags:string, json_ld:list<array<string, mixed>>}
     */
    public function for(string $type, ?Model $model, array $options = []): array
    {
        $meta = $this->subjects->meta($type, $model);
        $facts = $this->subjects->facts($type, $model);
        $resolved = $this->resolve($type, $meta, $facts);

        $robots = [];
        if (! $meta['index'] || ($options['noindex'] ?? false)) {
            $robots[] = 'noindex';
        }
        if (! $meta['follow']) {
            $robots[] = 'nofollow';
        }
        if ($robots !== [] && ! in_array('nofollow', $robots, true)) {
            $robots[] = 'follow';
        }

        $image = $meta['og_media_id'] ? Media::query()->find($meta['og_media_id']) : null;
        $image ??= $facts['image'] ?? $this->defaultImage();

        return [
            'title' => $resolved['title'],
            'description' => $resolved['description'],
            'canonical' => ($options['canonical'] ?? null) ?: ($meta['canonical_url'] ?: $facts['url']),
            'robots' => $robots === [] ? null : implode(', ', $robots),
            'og' => [
                'title' => $meta['og_title'] ?: $resolved['title'],
                'description' => $meta['og_description'] ?: $resolved['description'],
                'image' => $image?->sizeUrl(1200),
                'image_alt' => $image ? ($image->alt ?: $facts['name']) : null,
                'type' => $facts['og_type'],
                'url' => $facts['url'],
            ],
            'custom_tags' => SeoSettings::cleanTags($meta['custom_tags']),
            'json_ld' => $options['json_ld'] ?? [],
        ];
    }

    /**
     * A page that is not an editable item (the blog index, the store reviews page): a title and
     * description written by the page, with the site-wide defaults around them.
     *
     * @param  array{canonical?:?string, noindex?:bool, json_ld?:list<array<string, mixed>>}  $options
     * @return array<string, mixed>
     */
    public function page(string $title, string $description = '', array $options = []): array
    {
        $image = $this->defaultImage();
        $full = $title.' · '.$this->storeName();

        return [
            'title' => $full,
            'description' => $description,
            'canonical' => ($options['canonical'] ?? null) ?: url()->current(),
            'robots' => ($options['noindex'] ?? false) ? 'noindex, follow' : null,
            'og' => ['title' => $full, 'description' => $description, 'image' => $image?->sizeUrl(1200), 'image_alt' => $image?->alt, 'type' => 'website', 'url' => url()->current()],
            'custom_tags' => '',
            'json_ld' => $options['json_ld'] ?? [],
        ];
    }

    /**
     * The title and description a page ends up with.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $facts
     * @return array{title:string, description:string, title_from_template:bool, description_from_template:bool}
     */
    public function resolve(string $type, array $meta, array $facts): array
    {
        $templates = $this->settings->all()['templates'][$type];
        $title = trim((string) $meta['meta_title']);
        $description = trim((string) $meta['meta_description']);

        return [
            'title' => $title !== '' ? $title : $this->fill($templates['title'], $facts['variables']),
            'description' => $description !== '' ? $description : Str::limit($this->fill($templates['description'], $facts['variables']), 300, ''),
            'title_from_template' => $title === '',
            'description_from_template' => $description === '',
        ];
    }

    /** @param array<string, string> $variables */
    public function fill(string $template, array $variables): string
    {
        $text = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => $variables[$m[1]] ?? '', $template);

        // A variable that is empty for this item must not leave stray separators behind.
        return trim(preg_replace(['/\s+/', '/^[\s·|:,\-]+|[\s·|:,\-]+$/u'], [' ', ''], (string) $text));
    }

    /**
     * What every storefront page carries, whatever it shows.
     *
     * @return array{verification:string, head:string, body_start:string, body_end:string}
     */
    public function site(): array
    {
        $settings = $this->settings->all();

        return [
            'verification' => SeoSettings::cleanTags($settings['verification']),
            'head' => $settings['head'],
            'body_start' => $settings['body_start'],
            'body_end' => $settings['body_end'],
        ];
    }

    private function defaultImage(): ?Media
    {
        $id = $this->settings->all()['share_media_id'];

        return $id ? Media::query()->find($id) : null;
    }

    private function storeName(): string
    {
        return (string) Setting::get('store.name', config('app.name'));
    }
}
