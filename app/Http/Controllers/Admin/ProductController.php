<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DigitalAsset;
use App\Models\Product;
use App\Models\Variant;
use App\Services\ActivityLogger;
use App\Services\ProductWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(private ProductWriter $writer, private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Product::STATUSES)],
            'category' => ['nullable', 'integer'],
        ]);

        $products = Product::query()
            ->with(['category:id,name', 'images', 'variants'])
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like));
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(! isset($filters['status']), fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category_id', $category))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'status' => $p->status,
                'category' => $p->category?->name,
                'priceCents' => $p->fromPriceCents(),
                'hasVariants' => $p->hasVariants(),
                'variantCount' => $p->variants->count(),
                'trackStock' => $p->track_stock,
                'stock' => $p->hasVariants() ? (int) $p->variants->sum('stock') : $p->stock,
                'thumb' => $p->images->first()?->sizeUrl(320),
            ]);

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => (object) array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Products/Edit', [
            'product' => null,
            'categories' => $this->categoryOptions(),
            'maxFileMb' => round(config('store.digital_file_max_kb') / 1024),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $product = $this->writer->save(new Product, $this->validated($request));
        $this->activity->log('create', 'products', $product, $product->name);

        return redirect()->route('admin.products.edit', $product)->with('success', 'Product created.');
    }

    public function edit(Product $product): Response
    {
        $product->load(['images', 'tags', 'options.values', 'variants.optionValues', 'variants.image', 'digitalAssets', 'seo']);

        return Inertia::render('Products/Edit', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'short_description' => $product->short_description ?? '',
                'description' => $product->description ?? '',
                'category_id' => $product->category_id,
                'tags' => $product->tags->pluck('name')->all(),
                'status' => $product->status,
                'price' => $this->dollars($product->price_cents),
                'compare_at' => $this->dollars($product->compare_at_cents),
                'sku' => $product->sku ?? '',
                'brand' => $product->brand ?? '',
                'track_stock' => $product->track_stock,
                'stock' => $product->stock,
                'images' => $product->images->map->toAdminArray()->values(),
                'options' => $product->options->map(fn ($o) => [
                    'name' => $o->name,
                    'display' => $o->display,
                    'values' => $o->values->map(fn ($v) => ['value' => $v->value, 'swatch_hex' => $v->swatch_hex])->values(),
                ])->values(),
                'variants' => $product->variants->map(fn (Variant $v) => [
                    'id' => $v->id,
                    'values' => $v->optionValues->sortBy('product_option_id')->pluck('value')->values(),
                    'price' => $this->dollars($v->price_cents),
                    'compare_at' => $this->dollars($v->compare_at_cents),
                    'sku' => $v->sku ?? '',
                    'stock' => $v->stock,
                    'media_id' => $v->media_id,
                    'image' => $v->image?->toAdminArray(),
                ])->values(),
                'files' => $product->digitalAssets->map(fn (DigitalAsset $a) => [
                    'id' => $a->id,
                    'filename' => $a->filename,
                    'sizeBytes' => $a->size_bytes,
                    'version' => $a->version,
                    'variantId' => $a->variant_id,
                ])->values(),
                'seo' => [
                    'meta_title' => $product->seo?->meta_title ?? '',
                    'meta_description' => $product->seo?->meta_description ?? '',
                ],
            ],
            'categories' => $this->categoryOptions(),
            'maxFileMb' => round(config('store.digital_file_max_kb') / 1024),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $before = $product->only(['name', 'slug', 'status', 'price_cents', 'stock']);
        $product = $this->writer->save($product, $this->validated($request, $product));

        $changes = [];
        foreach ($before as $key => $old) {
            if ($product->{$key} != $old) {
                $changes[$key] = ['from' => $old, 'to' => $product->{$key}];
            }
        }
        $this->activity->log('edit', 'products', $product, $product->name, $changes);

        return back()->with('success', 'Product saved.');
    }

    public function duplicate(Product $product): RedirectResponse
    {
        $product->load(['images', 'tags', 'options.values', 'variants.optionValues', 'seo']);

        $copy = $this->writer->save(new Product, [
            'name' => $product->name.' (copy)',
            'slug' => $this->writer->uniqueSlug($product->slug.'-copy'),
            'category_id' => $product->category_id,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'status' => 'draft',
            'price' => $this->dollars($product->price_cents),
            'compare_at' => $this->dollars($product->compare_at_cents),
            'sku' => null,
            'brand' => $product->brand,
            'track_stock' => $product->track_stock,
            'stock' => $product->stock,
            'tags' => $product->tags->pluck('name')->all(),
            'image_ids' => $product->images->pluck('id')->all(),
            'options' => $product->options->map(fn ($o) => [
                'name' => $o->name,
                'display' => $o->display,
                'values' => $o->values->map(fn ($v) => ['value' => $v->value, 'swatch_hex' => $v->swatch_hex])->all(),
            ])->all(),
            'variants' => $product->variants->map(fn (Variant $v) => [
                'values' => $v->optionValues->sortBy('product_option_id')->pluck('value')->values()->all(),
                'price' => $this->dollars($v->price_cents),
                'compare_at' => $this->dollars($v->compare_at_cents),
                'sku' => null,
                'stock' => $v->stock,
                'media_id' => $v->media_id,
            ])->all(),
            'seo' => ['meta_title' => $product->seo?->meta_title, 'meta_description' => $product->seo?->meta_description],
        ]);

        $this->activity->log('create', 'products', $copy, $copy->name, ['copied_from' => $product->name]);

        return redirect()->route('admin.products.edit', $copy)
            ->with('success', 'Product duplicated as a draft. Its digital files were not copied.');
    }

    public function setStatus(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Product::STATUSES)]]);
        $old = $product->status;
        $product->update($data);

        $this->activity->log($data['status'] === 'active' ? 'publish' : 'edit', 'products', $product, $product->name, [
            'status' => ['from' => $old, 'to' => $data['status']],
        ]);

        return back()->with('success', match ($data['status']) {
            'active' => 'Product is live on the storefront.',
            'archived' => 'Product archived. It is hidden from the storefront.',
            default => 'Product moved to drafts.',
        });
    }

    public function destroy(Product $product): RedirectResponse
    {
        $name = $product->name;
        foreach ($product->digitalAssets as $asset) {
            Storage::disk($asset->disk)->delete($asset->path);
        }
        $this->activity->log('delete', 'products', $product, $name);
        $product->delete();

        return redirect()->route('admin.products.index')->with('success', "{$name} was deleted.");
    }

    // ---- Digital files -------------------------------------------------

    public function storeFile(Request $request, Product $product): RedirectResponse
    {
        $maxKb = (int) config('store.digital_file_max_kb');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb],
            'version' => ['nullable', 'string', 'max:40'],
            'variant_id' => ['nullable', 'integer', Rule::exists('variants', 'id')->where('product_id', $product->id)],
        ], ['file.max' => 'The file can be up to '.round($maxKb / 1024).' MB.']);

        $file = $data['file'];
        $path = $file->storeAs('products/'.$product->id.'/'.Str::lower(Str::random(16)), $file->getClientOriginalName(), 'downloads');

        $asset = $product->digitalAssets()->create([
            'variant_id' => $data['variant_id'] ?? null,
            'disk' => 'downloads',
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'size_bytes' => $file->getSize(),
            'version' => $data['version'] ?? null,
        ]);

        $this->activity->log('create', 'products', $product, $product->name, ['file' => $asset->filename]);

        return back()->with('success', "{$asset->filename} uploaded.");
    }

    public function destroyFile(Product $product, DigitalAsset $file): RedirectResponse
    {
        abort_unless($file->product_id === $product->id, 404);

        Storage::disk($file->disk)->deleteDirectory(dirname($file->path));
        $this->activity->log('delete', 'products', $product, $product->name, ['file' => $file->filename]);
        $file->delete();

        return back()->with('success', 'File removed.');
    }

    // ---- Helpers --------------------------------------------------------

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Product $product = null): array
    {
        $money = ['numeric', 'min:0', 'max:999999.99'];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product?->id)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'status' => ['required', Rule::in(Product::STATUSES)],
            'price' => ['required', ...$money],
            'compare_at' => ['nullable', ...$money],
            'sku' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'track_stock' => ['boolean'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'tags' => ['array', 'max:30'],
            'tags.*' => ['string', 'max:50'],
            'image_ids' => ['array', 'max:30'],
            'image_ids.*' => ['integer', Rule::exists('media', 'id')->where('type', 'image')],
            'options' => ['array', 'max:2'],
            'options.*.name' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
            'options.*.display' => ['nullable', Rule::in(['pill', 'swatch'])],
            'options.*.values' => ['required', 'array', 'min:1', 'max:30'],
            'options.*.values.*.value' => ['required', 'string', 'max:50'],
            'options.*.values.*.swatch_hex' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'variants' => ['array', 'max:200'],
            'variants.*.values' => ['required', 'array'],
            'variants.*.values.*' => ['string', 'max:50'],
            'variants.*.price' => ['required', ...$money],
            'variants.*.compare_at' => ['nullable', ...$money],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'variants.*.media_id' => ['nullable', 'integer', Rule::exists('media', 'id')],
            'seo.meta_title' => ['nullable', 'string', 'max:255'],
            'seo.meta_description' => ['nullable', 'string', 'max:500'],
        ], [
            'slug.regex' => 'Use lower-case letters, numbers and hyphens only.',
            'options.*.values.required' => 'Add at least one value to each option.',
            'options.*.name.distinct' => 'Each option needs a different name.',
        ]);

        if (($data['compare_at'] ?? null) !== null && (float) $data['compare_at'] <= (float) $data['price']) {
            throw ValidationException::withMessages(['compare_at' => 'The compare-at price must be higher than the price.']);
        }

        foreach ($data['options'] ?? [] as $index => $option) {
            $values = array_map(fn ($v) => Str::lower(trim($v['value'])), $option['values']);
            if (count($values) !== count(array_unique($values))) {
                throw ValidationException::withMessages(["options.{$index}.values" => 'Each value can be listed once.']);
            }
        }

        if (! empty($data['options']) && empty($data['variants'])) {
            throw ValidationException::withMessages(['variants' => 'Add at least one variant, or remove the options.']);
        }

        return $data;
    }

    /** @return list<array{id:int, name:string}> */
    private function categoryOptions(): array
    {
        $options = [];
        $tops = Category::query()->whereNull('parent_id')->with('children')->orderBy('position')->orderBy('name')->get();
        foreach ($tops as $top) {
            $options[] = ['id' => $top->id, 'name' => $top->name];
            foreach ($top->children as $child) {
                $options[] = ['id' => $child->id, 'name' => $top->name.' › '.$child->name];
            }
        }

        return $options;
    }

    private function dollars(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }
}
