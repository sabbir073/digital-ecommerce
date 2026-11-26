<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * The block library for selling pages (PRD section 5): which blocks exist, what each one
 * can contain, and the cleaning applied to block data before it is stored or shown.
 *
 * A block is ['id' => string, 'type' => string, 'hidden' => bool, 'style' => [...], 'data' => [...]].
 */
class Blocks
{
    public const BACKGROUNDS = ['white', 'soft', 'dark'];

    public const SPACINGS = ['sm', 'md', 'lg'];

    public const ALIGNS = ['left', 'center'];

    /**
     * Field types: text, textarea, richtext, toggle, select, image, video, images, reviews, datetime, url,
     * strings (list of lines), list (repeated group of fields), table.
     *
     * @return array<string, array{label:string, fields:list<array<string, mixed>>}>
     */
    public static function library(): array
    {
        $cards = fn (string $label) => [
            'key' => 'items', 'label' => $label, 'type' => 'list', 'min' => 2, 'max' => 4, 'itemLabel' => 'Card',
            'fields' => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'max' => 80],
                ['key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'max' => 300],
            ],
        ];
        $side = ['key' => 'image_side', 'label' => 'Image side', 'type' => 'select', 'options' => ['right' => 'Right', 'left' => 'Left'], 'default' => 'right'];

        return [
            'hero' => ['label' => 'Hero', 'fields' => [
                ['key' => 'badge', 'label' => 'Badge text', 'type' => 'text', 'max' => 80],
                ['key' => 'headline', 'label' => 'Headline', 'type' => 'text', 'max' => 120],
                ['key' => 'subline', 'label' => 'Sub-line', 'type' => 'textarea', 'max' => 300],
                ['key' => 'show_price', 'label' => 'Show the price', 'type' => 'toggle', 'default' => true],
                ['key' => 'button_label', 'label' => 'Button label', 'type' => 'text', 'max' => 40, 'default' => 'Order now'],
                ['key' => 'note', 'label' => 'Small note under the button', 'type' => 'text', 'max' => 120],
                ['key' => 'image_id', 'label' => 'Image', 'type' => 'image'],
                ['key' => 'video_url', 'label' => 'Video link (used instead of the image)', 'type' => 'url'],
                $side,
            ]],
            'benefits' => ['label' => 'Benefits', 'fields' => [$cards('Cards')]],
            'image_text' => ['label' => 'Image and text', 'fields' => [
                ['key' => 'image_id', 'label' => 'Image', 'type' => 'image'],
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'max' => 120],
                ['key' => 'text', 'label' => 'Paragraph', 'type' => 'textarea', 'max' => 1000],
                ['key' => 'bullets', 'label' => 'Bullet list', 'type' => 'strings', 'max' => 8, 'itemMax' => 160],
                ['key' => 'image_side', 'label' => 'Image side', 'type' => 'select', 'options' => ['left' => 'Left', 'right' => 'Right'], 'default' => 'left'],
            ]],
            'gallery' => ['label' => 'Gallery', 'fields' => [
                ['key' => 'image_ids', 'label' => 'Images (two to eight)', 'type' => 'images', 'min' => 2, 'max' => 8],
                ['key' => 'layout', 'label' => 'Layout', 'type' => 'select', 'options' => ['grid' => 'Grid', 'slider' => 'Slider'], 'default' => 'grid'],
            ]],
            'video' => ['label' => 'Video', 'fields' => [
                ['key' => 'video_id', 'label' => 'Uploaded video', 'type' => 'video'],
                ['key' => 'url', 'label' => 'Or a video link (YouTube or Vimeo)', 'type' => 'url'],
                ['key' => 'poster_id', 'label' => 'Poster image', 'type' => 'image'],
                ['key' => 'title', 'label' => 'Title read by screen readers', 'type' => 'text', 'max' => 120],
            ]],
            'reviews' => ['label' => 'Reviews', 'fields' => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'max' => 120, 'default' => 'What customers say'],
                ['key' => 'mode', 'label' => 'Which reviews', 'type' => 'select', 'options' => ['newest' => 'Newest first', 'picked' => 'Hand-picked'], 'default' => 'newest'],
                ['key' => 'review_ids', 'label' => 'Hand-picked reviews', 'type' => 'reviews', 'max' => 12],
                ['key' => 'limit', 'label' => 'How many to show', 'type' => 'select', 'options' => ['3' => '3', '6' => '6', '9' => '9'], 'default' => '3'],
            ]],
            'faq' => ['label' => 'FAQ', 'fields' => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'max' => 120, 'default' => 'Questions'],
                ['key' => 'items', 'label' => 'Questions', 'type' => 'list', 'min' => 1, 'max' => 20, 'itemLabel' => 'Question', 'fields' => [
                    ['key' => 'q', 'label' => 'Question', 'type' => 'text', 'max' => 200],
                    ['key' => 'a', 'label' => 'Answer', 'type' => 'textarea', 'max' => 1500],
                ]],
            ]],
            'offer_bar' => ['label' => 'Offer bar', 'fields' => [
                ['key' => 'text', 'label' => 'Text', 'type' => 'text', 'max' => 160],
                ['key' => 'ends_at', 'label' => 'Countdown to (optional). The bar hides after this time.', 'type' => 'datetime'],
            ]],
            'table' => ['label' => 'Comparison or specs table', 'fields' => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'max' => 120],
                ['key' => 'table', 'label' => 'Table', 'type' => 'table', 'maxColumns' => 5, 'maxRows' => 20, 'cellMax' => 160],
            ]],
            'rich_text' => ['label' => 'Rich text', 'fields' => [
                ['key' => 'html', 'label' => 'Text', 'type' => 'richtext', 'max' => 20000],
            ]],
            'trust' => ['label' => 'Trust row', 'fields' => [$cards('Items')]],
            'order' => ['label' => 'Order block', 'fields' => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'max' => 120, 'default' => 'Complete your order'],
                ['key' => 'subline', 'label' => 'Sub-line', 'type' => 'text', 'max' => 200, 'default' => 'No account, no redirect. Order and pay right here.'],
                ['key' => 'pay_label', 'label' => 'Pay button label (the total is added after it)', 'type' => 'text', 'max' => 30, 'default' => 'Pay'],
            ]],
        ];
    }

    /** A new, empty block of the given type. */
    public static function make(string $type, array $data = [], array $style = []): array
    {
        return self::normalizeBlock(['id' => (string) Str::ulid(), 'type' => $type, 'data' => $data, 'style' => $style]);
    }

    /**
     * Clean a list of blocks: unknown types and fields are dropped, text is trimmed and
     * shortened, rich text is sanitised.
     *
     * @param  mixed  $blocks
     * @return list<array<string, mixed>>
     */
    public static function normalize($blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        $clean = [];
        $ids = [];
        foreach (array_slice(array_values($blocks), 0, 60) as $block) {
            if (! is_array($block) || ! isset(self::library()[$block['type'] ?? ''])) {
                continue;
            }
            $block = self::normalizeBlock($block);
            if (isset($ids[$block['id']])) {
                $block['id'] = (string) Str::ulid();
            }
            $ids[$block['id']] = true;
            $clean[] = $block;
        }

        return $clean;
    }

    private static function normalizeBlock(array $block): array
    {
        $type = $block['type'];
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $style = is_array($block['style'] ?? null) ? $block['style'] : [];
        $id = is_string($block['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{6,40}$/', $block['id']) ? $block['id'] : (string) Str::ulid();

        $out = [];
        foreach (self::library()[$type]['fields'] as $field) {
            $out[$field['key']] = self::value($field, $data[$field['key']] ?? null, array_key_exists($field['key'], $data));
        }

        return [
            'id' => $id,
            'type' => $type,
            'hidden' => (bool) ($block['hidden'] ?? false),
            'style' => [
                'background' => in_array($style['background'] ?? null, self::BACKGROUNDS, true) ? $style['background'] : self::defaultBackground($type),
                'spacing' => in_array($style['spacing'] ?? null, self::SPACINGS, true) ? $style['spacing'] : 'md',
                'align' => in_array($style['align'] ?? null, self::ALIGNS, true) ? $style['align'] : 'left',
            ],
            'data' => $out,
        ];
    }

    private static function defaultBackground(string $type): string
    {
        return match ($type) {
            'benefits', 'order' => 'soft',
            'offer_bar' => 'dark',
            default => 'white',
        };
    }

    /** @param array<string, mixed> $field */
    private static function value(array $field, mixed $value, bool $given): mixed
    {
        $text = fn (mixed $v, int $max) => is_scalar($v) ? Str::limit(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v)), $max, '') : '';

        switch ($field['type']) {
            case 'text':
                return $given ? $text($value, $field['max'] ?? 200) : ($field['default'] ?? '');
            case 'textarea':
                return $given ? $text($value, $field['max'] ?? 1000) : ($field['default'] ?? '');
            case 'richtext':
                return HtmlSanitizer::clean(is_string($value) ? Str::limit($value, $field['max'] ?? 20000, '') : '');
            case 'toggle':
                return $given ? filter_var($value, FILTER_VALIDATE_BOOL) : (bool) ($field['default'] ?? false);
            case 'select':
                return is_scalar($value) && array_key_exists((string) $value, $field['options']) ? (string) $value : $field['default'];
            case 'image':
            case 'video':
                return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
            case 'images':
            case 'reviews':
                $ids = is_array($value) ? array_values(array_unique(array_filter(array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $value)))) : [];

                return array_slice($ids, 0, $field['max'] ?? 12);
            case 'datetime':
                try {
                    return is_string($value) && trim($value) !== '' ? Carbon::parse($value)->utc()->toIso8601String() : null;
                } catch (Throwable) {
                    return null;
                }
            case 'url':
                $url = $text($value, 500);

                return preg_match('~^(https://|/(?!/))~i', $url) ? $url : '';
            case 'strings':
                $lines = is_array($value) ? array_values(array_filter(array_map(fn ($v) => $text($v, $field['itemMax'] ?? 160), $value), fn ($v) => $v !== '')) : [];

                return array_slice($lines, 0, $field['max'] ?? 10);
            case 'list':
                $items = [];
                foreach (array_slice(array_values(array_filter(is_array($value) ? $value : [], 'is_array')), 0, $field['max'] ?? 20) as $item) {
                    $row = [];
                    foreach ($field['fields'] as $sub) {
                        $row[$sub['key']] = self::value($sub, $item[$sub['key']] ?? null, array_key_exists($sub['key'], $item));
                    }
                    $items[] = $row;
                }

                return $items;
            case 'table':
                $value = is_array($value) ? $value : [];
                $columns = array_slice(array_map(fn ($v) => $text($v, $field['cellMax']), array_values(is_array($value['columns'] ?? null) ? $value['columns'] : [])), 0, $field['maxColumns']);
                $rows = [];
                foreach (array_slice(array_values(is_array($value['rows'] ?? null) ? $value['rows'] : []), 0, $field['maxRows']) as $row) {
                    $cells = array_map(fn ($v) => $text($v, $field['cellMax']), array_values(is_array($row) ? $row : []));
                    $rows[] = array_slice(array_pad($cells, count($columns), ''), 0, count($columns));
                }

                return ['columns' => $columns, 'rows' => $columns === [] ? [] : $rows];
        }

        return null;
    }

    /**
     * The ready-made page from the design reference: Hero, Benefits, Image and text, FAQ, Order block.
     * What the store already knows comes from the product. Everything only the owner knows is left as
     * a [BRACKETED] placeholder, and a page cannot be published until those are replaced.
     *
     * @return list<array<string, mixed>>
     */
    public static function starter(Product $product): array
    {
        $image = $product->images->first()?->id;

        return [
            self::make('hero', [
                'badge' => '',
                'headline' => $product->name,
                'subline' => $product->short_description ?: '[ONE-SENTENCE PROMISE: the main result the customer gets from this product]',
                'show_price' => true,
                'button_label' => 'Order now',
                'note' => 'Secure card payment by Stripe · Instant download',
                'image_id' => $image,
            ]),
            self::make('benefits', ['items' => [
                ['title' => '[BENEFIT 1]', 'text' => '[One line on why this matters to the buyer]'],
                ['title' => '[BENEFIT 2]', 'text' => '[One line on why this matters to the buyer]'],
                ['title' => '[BENEFIT 3]', 'text' => '[One line on why this matters to the buyer]'],
            ]]),
            self::make('image_text', [
                'image_id' => $product->images->get(1)?->id ?? $image,
                'heading' => '[FEATURE HEADLINE]',
                'text' => '[FEATURE STORY: a short paragraph that explains the product in use]',
                'bullets' => ['[FEATURE 1]', '[FEATURE 2]', '[FEATURE 3]'],
                'image_side' => 'left',
            ]),
            self::make('faq', ['heading' => 'Questions', 'items' => [
                ['q' => 'How do I get my files?', 'a' => 'Right after payment you get a download link on this page and by email.'],
                ['q' => 'Is payment secure?', 'a' => 'Yes. Card details go straight to Stripe and never touch our servers.'],
                ['q' => 'Can I get a refund?', 'a' => '[YOUR REFUND POLICY]'],
            ]]),
            self::make('order'),
        ];
    }

    /**
     * Reasons a page cannot be published yet, in plain language. Empty means it can.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    public static function problems(array $blocks): array
    {
        $problems = [];
        $visible = array_values(array_filter($blocks, fn ($b) => ! ($b['hidden'] ?? false)));
        $orders = count(array_filter($visible, fn ($b) => $b['type'] === 'order'));

        if ($orders === 0) {
            $problems[] = 'Add an Order block. Customers pay in it, so a page cannot go live without one.';
        } elseif ($orders > 1) {
            $problems[] = 'Keep only one Order block. A page can have exactly one.';
        }

        $labels = [];
        foreach ($visible as $block) {
            $found = false;
            array_walk_recursive($block['data'], function ($value) use (&$found) {
                // [WORDS IN CAPITALS] is text the owner still has to write.
                if (is_string($value) && preg_match('/\[[A-Z][^\[\]a-z]*[A-Z0-9][^\[\]]*\]|\[One line[^\]]*\]/', $value)) {
                    $found = true;
                }
            });
            if ($found) {
                $labels[] = self::library()[$block['type']]['label'];
            }
        }
        if ($labels !== []) {
            $problems[] = 'Replace the placeholder text in [BRACKETS] in: '.implode(', ', array_unique($labels)).'.';
        }

        foreach ($visible as $block) {
            if ($block['type'] === 'gallery' && count($block['data']['image_ids']) < 2) {
                $problems[] = 'The Gallery block needs at least two images.';
            }
            if (in_array($block['type'], ['benefits', 'trust'], true) && count($block['data']['items']) < 2) {
                $problems[] = self::library()[$block['type']]['label'].' needs at least two cards.';
            }
        }

        return array_values(array_unique($problems));
    }
}
