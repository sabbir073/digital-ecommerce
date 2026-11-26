<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One section of the home page. The admin can reorder sections, switch them
 * off and edit their content; anything not yet edited falls back to the
 * defaults below.
 */
class HomeSection extends Model
{
    public const TYPES = ['hero', 'categories', 'best_sellers', 'featured_offer', 'trust'];

    protected $fillable = ['type', 'enabled', 'position', 'settings'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'settings' => 'array'];
    }

    /** @return array<string, array<string, mixed>> */
    public static function defaults(): array
    {
        return [
            'hero' => [
                'eyebrow' => 'Instant download',
                'headline' => 'Digital products, ready the moment you pay.',
                'text' => 'Pay securely and download straight away.',
                'primary_label' => 'Shop all products',
                'primary_url' => '/products',
                'secondary_label' => 'Best sellers',
                'secondary_url' => '/products?sort=best',
                'media_ids' => [],
            ],
            'categories' => ['heading' => 'Shop by category'],
            'best_sellers' => ['heading' => 'Best sellers', 'product_ids' => []],
            'featured_offer' => ['selling_page_id' => null, 'eyebrow' => null, 'headline' => null, 'text' => null, 'button_label' => null],
            'trust' => [
                'items' => [
                    ['icon' => 'download', 'title' => 'Instant delivery', 'text' => 'Your download link arrives right after payment.'],
                    ['icon' => 'lock', 'title' => 'Secure payment', 'text' => 'Card details are handled by Stripe and never touch our servers.'],
                    ['icon' => 'refresh', 'title' => 'Returns', 'text' => 'Read our returns policy before you buy.'],
                    ['icon' => 'chat', 'title' => 'Support', 'text' => 'Questions? Our team is here to help.'],
                ],
            ],
        ];
    }

    /**
     * Enabled sections in display order, each with its settings merged over the defaults.
     *
     * @return Collection<int, array{type:string, settings:array<string, mixed>}>
     */
    public static function forHome(): Collection
    {
        $saved = static::query()->get()->keyBy('type');
        $defaults = static::defaults();

        return collect(self::TYPES)
            ->map(function (string $type, int $index) use ($saved, $defaults) {
                $row = $saved->get($type);

                return [
                    'type' => $type,
                    'enabled' => $row ? $row->enabled : true,
                    'position' => $row ? $row->position : $index,
                    'settings' => array_merge($defaults[$type], $row?->settings ?? []),
                ];
            })
            ->where('enabled', true)
            ->sortBy('position')
            ->values();
    }
}
