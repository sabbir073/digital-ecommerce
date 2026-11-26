<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Variant;
use App\Services\Reviews\ReviewListing;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __invoke(Request $request, ReviewListing $reviews, string $slug): View
    {
        $product = Product::query()->visible()->where('slug', $slug)
            ->with(['category.parent', 'images', 'options.values', 'variants.optionValues', 'variants.image', 'seo'])
            ->firstOrFail();

        // Gallery: the product's images, then any variant image not already in it.
        $gallery = $product->images->values();
        foreach ($product->variants as $variant) {
            if ($variant->image && ! $gallery->contains('id', $variant->image->id)) {
                $gallery->push($variant->image);
            }
        }

        $related = Product::query()->visible()
            ->with(['category:id,name,slug', 'images', 'variants'])
            ->where('category_id', $product->category_id)
            ->whereNotNull('category_id')
            ->whereKeyNot($product->id)
            ->orderByDesc('sales_count')->orderByDesc('id')
            ->limit(4)->get();

        return view('storefront.product', $reviews->build($product->id, $request) + [
            'form' => ReviewController::formContext($request, $product),
            'product' => $product,
            'gallery' => $gallery,
            'related' => $related,
            'sellingPage' => $product->sellingPages()->published()->latest('published_at')->first(),
            'state' => $this->state($product),
            'deliveryText' => Setting::get('product.delivery_text', 'This is a digital product. Your download link is shown right after payment and sent to your email.'),
            'returnsText' => Setting::get('product.returns_text'),
        ]);
    }

    /**
     * Everything the product page script needs to react to a variant choice.
     *
     * @return array<string, mixed>
     */
    private function state(Product $product): array
    {
        return [
            'tracked' => $product->track_stock,
            'base' => [
                'price' => (int) $product->price_cents,
                'compareAt' => $product->compare_at_cents !== null ? (int) $product->compare_at_cents : null,
                'stock' => $product->availableStock(),
            ],
            'variants' => $product->variants->map(fn (Variant $v) => [
                'id' => $v->id,
                'values' => $v->optionValues->pluck('id')->all(),
                'price' => (int) $v->price_cents,
                'compareAt' => $v->compare_at_cents !== null ? (int) $v->compare_at_cents : null,
                'stock' => $product->availableStock($v),
                'mediaId' => $v->media_id,
            ])->all(),
        ];
    }

    public static function imageAlt(Media $media, Product $product): string
    {
        return $media->alt ?: $product->name;
    }
}
