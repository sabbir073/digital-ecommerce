<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\ActivityLogger;
use App\Services\ProductWriter;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Bulk import and export of products by CSV (PRD section 7). Variants are edited in admin, not by CSV. */
class ProductCsvController extends Controller
{
    public const COLUMNS = [
        'name', 'slug', 'status', 'category', 'tags', 'price', 'compare_at_price',
        'sku', 'track_stock', 'stock', 'short_description', 'description',
    ];

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::COLUMNS);

            Product::query()->with(['category', 'tags'])->orderBy('id')->chunk(200, function ($products) use ($out) {
                foreach ($products as $p) {
                    fputcsv($out, [
                        $p->name, $p->slug, $p->status, $p->category?->slug, $p->tags->pluck('name')->implode('|'),
                        number_format($p->price_cents / 100, 2, '.', ''),
                        $p->compare_at_cents === null ? '' : number_format($p->compare_at_cents / 100, 2, '.', ''),
                        $p->sku, $p->track_stock ? 1 : 0, $p->stock, $p->short_description, $p->description,
                    ]);
                }
            });

            fclose($out);
        }, 'products-'.date('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function import(Request $request, ProductWriter $writer, ActivityLogger $activity): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => Str::lower(trim((string) $h)), fgetcsv($handle) ?: []);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? ''); // Excel BOM

        if (! in_array('name', $header, true) || ! in_array('price', $header, true)) {
            fclose($handle);

            return back()->with('error', 'The CSV needs at least the columns "name" and "price". Export a file to see the format.');
        }

        $categories = Category::query()->pluck('id', 'slug');
        $created = $updated = 0;
        $errors = [];
        $line = 1;

        DB::transaction(function () use ($handle, $header, $categories, $writer, &$created, &$updated, &$errors, &$line) {
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($row === [null] || count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                $data = [];
                foreach ($header as $i => $column) {
                    $data[$column] = isset($row[$i]) ? trim((string) $row[$i]) : '';
                }

                $price = Money::toCents($data['price'] ?? '');
                if ($data['name'] === '' || $price === null || $price < 0) {
                    $errors[] = "Line {$line}: name and a valid price are required.";

                    continue;
                }
                $status = $data['status'] ?? '';
                if ($status !== '' && ! in_array($status, Product::STATUSES, true)) {
                    $errors[] = "Line {$line}: status must be draft, active or archived.";

                    continue;
                }
                $categorySlug = $data['category'] ?? '';
                if ($categorySlug !== '' && ! isset($categories[$categorySlug])) {
                    $errors[] = "Line {$line}: category \"{$categorySlug}\" does not exist.";

                    continue;
                }
                $compare = Money::toCents($data['compare_at_price'] ?? '');
                if ($compare !== null && $compare <= $price) {
                    $errors[] = "Line {$line}: compare-at price must be higher than the price.";

                    continue;
                }

                $slug = Str::slug($data['slug'] ?? '') ?: null;
                $product = $slug ? Product::query()->where('slug', $slug)->first() : null;
                $isNew = $product === null;
                $product ??= new Product(['status' => 'draft']);

                $product->fill([
                    'name' => $data['name'],
                    'slug' => $slug ?? $writer->uniqueSlug($data['name']),
                    'status' => $status !== '' ? $status : $product->status,
                    'category_id' => $categorySlug !== '' ? $categories[$categorySlug] : $product->category_id,
                    'price_cents' => $price,
                    'compare_at_cents' => $compare,
                    'sku' => ($data['sku'] ?? '') !== '' ? $data['sku'] : $product->sku,
                    'track_stock' => array_key_exists('track_stock', $data) ? in_array(Str::lower($data['track_stock']), ['1', 'yes', 'true'], true) : (bool) $product->track_stock,
                    'stock' => ($data['stock'] ?? '') !== '' ? max(0, (int) $data['stock']) : (int) $product->stock,
                    'short_description' => array_key_exists('short_description', $data) ? ($data['short_description'] ?: null) : $product->short_description,
                    'description' => array_key_exists('description', $data) ? ($data['description'] ?: null) : $product->description,
                ])->save();

                if (array_key_exists('tags', $data)) {
                    $writer->syncTags($product, array_filter(explode('|', $data['tags'])));
                }

                $isNew ? $created++ : $updated++;
            }
        });
        fclose($handle);

        $activity->log('create', 'products', null, 'CSV import', ['created' => $created, 'updated' => $updated, 'skipped' => count($errors)]);

        $summary = "{$created} created, {$updated} updated.";
        if ($errors !== []) {
            $shown = implode(' ', array_slice($errors, 0, 5));
            $more = count($errors) > 5 ? ' And '.(count($errors) - 5).' more.' : '';

            return back()->with('error', "Import finished: {$summary} ".count($errors)." skipped. {$shown}{$more}");
        }

        return back()->with('success', "Import finished: {$summary}");
    }
}
