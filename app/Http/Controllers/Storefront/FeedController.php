<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Product feeds for Google Merchant Center and the Meta catalog (PRD section 14). Built on request, so always current. */
class FeedController extends Controller
{
    /**
     * One row per product that is on sale. The ID is the product's own ID: the same one every tracking event carries.
     *
     * @return list<array<string, string>>
     */
    private function rows(): array
    {
        $store = (string) Setting::get('store.name', config('app.name'));

        return Product::query()->visible()->with(['images', 'variants', 'category.parent'])->orderBy('id')->get()
            ->map(function (Product $product) use ($store) {
                $images = $product->images->map(fn ($image) => $image->sizeUrl(1200))->values();
                $category = $product->category ? trim(($product->category->parent?->name ? $product->category->parent->name.' > ' : '').$product->category->name) : '';

                return [
                    'id' => (string) $product->id,
                    'title' => Str::limit($product->name, 150, ''),
                    'description' => Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($product->description ?: $product->short_description ?: $product->name)))), 4900, ''),
                    'link' => route('product', $product->slug),
                    'image_link' => (string) ($images->first() ?? ''),
                    'additional_image_link' => $images->slice(1, 10)->implode(','),
                    'availability' => $product->isInStock() ? 'in stock' : 'out of stock',
                    'price' => number_format($product->fromPriceCents() / 100, 2, '.', '').' USD',
                    'brand' => (string) ($product->brand ?: $store),
                    'condition' => 'new',
                    'product_type' => $category,
                ];
            })
            // Both services refuse an item without a picture.
            ->filter(fn (array $row) => $row['image_link'] !== '')->values()->all();
    }

    /** Google Merchant Center: RSS 2.0 with the g: namespace. */
    public function google(): Response
    {
        $store = (string) Setting::get('store.name', config('app.name'));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">'."\n<channel>\n"
            .'<title>'.e($store).'</title><link>'.e(url('/')).'</link><description>'.e($store).' products</description>'."\n";
        foreach ($this->rows() as $row) {
            $xml .= "<item>\n";
            foreach ($row as $key => $value) {
                if ($value === '') {
                    continue;
                }
                foreach ($key === 'additional_image_link' ? explode(',', $value) : [$value] as $one) {
                    $xml .= '  <g:'.$key.'>'.htmlspecialchars($one, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</g:'.$key.">\n";
                }
            }
            $xml .= "</item>\n";
        }

        return response($xml."</channel>\n</rss>\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** Meta catalog: a CSV with Meta's column names. */
    public function meta(): StreamedResponse
    {
        $rows = $this->rows();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'additional_image_link', 'brand', 'product_type'], ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, [$row['id'], $row['title'], $row['description'], $row['availability'], $row['condition'], $row['price'], $row['link'], $row['image_link'], $row['additional_image_link'], $row['brand'], $row['product_type']], ',', '"', '');
            }
            fclose($out);
        }, 'meta-catalog.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'inline; filename="meta-catalog.csv"']);
    }
}
