<?php

namespace App\Services\Selling;

use App\Models\Media;
use App\Models\Offer;
use App\Models\Review;
use App\Models\SellingPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Gathers everything the selling page templates need for a list of blocks. */
class PagePresenter
{
    public function __construct(private readonly OfferPricing $pricing) {}

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array{blocks: list<array<string, mixed>>, media: Collection<int, Media>, reviews: array<string, Collection<int, Review>>, offers: list<array<string, mixed>>, hasOrder: bool}
     */
    public function present(SellingPage $page, array $blocks, bool $builder = false): array
    {
        $visible = [];
        foreach ($blocks as $block) {
            // The builder shows everything, so hidden and expired blocks can still be selected and edited.
            if (! $builder) {
                if ($block['hidden'] ?? false) {
                    continue;
                }
                // A countdown that has passed hides the Offer bar. It never resets itself.
                if ($block['type'] === 'offer_bar' && ($block['data']['ends_at'] ?? null) && Carbon::parse($block['data']['ends_at'])->isPast()) {
                    continue;
                }
            }
            $visible[] = $block;
        }

        $mediaIds = [];
        foreach ($visible as $block) {
            foreach (['image_id', 'poster_id', 'video_id'] as $key) {
                if (! empty($block['data'][$key])) {
                    $mediaIds[] = $block['data'][$key];
                }
            }
            foreach ($block['data']['image_ids'] ?? [] as $id) {
                $mediaIds[] = $id;
            }
        }
        $media = $mediaIds === [] ? collect() : Media::query()->whereIn('id', array_unique($mediaIds))->get()->keyBy('id');

        $reviews = [];
        foreach ($visible as $block) {
            if ($block['type'] !== 'reviews') {
                continue;
            }
            // Approved reviews only, of the page's product or of the store as a whole.
            $scope = fn ($q) => ($block['data']['source'] ?? 'product') === 'store' ? $q->whereNull('product_id') : $q->where('product_id', $page->product_id);
            $query = Review::query()->approved()->with('photos');
            $reviews[$block['id']] = $block['data']['mode'] === 'picked'
                ? $query->where(fn ($q) => $q->where('product_id', $page->product_id)->orWhereNull('product_id'))->whereIn('id', $block['data']['review_ids'])->get()
                    ->sortBy(fn (Review $r) => array_search($r->id, $block['data']['review_ids'], true))->values()
                : $scope($query)->orderByDesc('pinned')->orderByRaw('COALESCE(reviewed_at, created_at) DESC')->orderByDesc('id')->limit((int) $block['data']['limit'])->get();
        }

        return [
            'blocks' => $visible,
            'media' => $media,
            'reviews' => $reviews,
            'offers' => $this->offers($page),
            'hasOrder' => collect($visible)->contains(fn ($b) => $b['type'] === 'order' && ! ($b['hidden'] ?? false)),
        ];
    }

    /** @return list<array{id:int, label:string, note:?string, price:int, default:bool, soldOut:bool, items:list<string>}> */
    public function offers(SellingPage $page): array
    {
        $page->loadMissing(['offers.items.product.images', 'offers.items.product.variants', 'offers.items.variant.optionValues', 'offers.items.variant.image']);

        $offers = $page->offers->map(fn (Offer $offer) => [
            'id' => $offer->id,
            'label' => $offer->label,
            'note' => $offer->note,
            'price' => (int) $offer->price_cents,
            'default' => (bool) $offer->is_default,
            'soldOut' => $this->pricing->problem($offer) !== null,
            'items' => $offer->items->map(fn ($item) => trim(($item->quantity > 1 ? $item->quantity.' × ' : '').($item->product?->name ?? '').($item->variant ? ' ('.$item->variant->label().')' : '')))->all(),
        ])->all();

        // Exactly one pack is pre-selected: the admin's default if it can be bought, else the first that can.
        $chosen = null;
        foreach ($offers as $index => $offer) {
            if (! $offer['soldOut'] && ($chosen === null || ($offer['default'] && ! $offers[$chosen]['default']))) {
                $chosen = $index;
            }
        }
        foreach ($offers as $index => &$offer) {
            $offer['default'] = $index === $chosen;
        }

        return $offers;
    }
}
