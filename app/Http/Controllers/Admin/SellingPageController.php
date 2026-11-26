<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\SellingPage;
use App\Models\Variant;
use App\Services\ActivityLogger;
use App\Support\Blocks;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Selling pages in admin: the list, page settings, packs, content and publishing (PRD section 5). */
class SellingPageController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        $views = DB::table('page_views')->selectRaw('selling_page_id, SUM(views) as views')->groupBy('selling_page_id')->pluck('views', 'selling_page_id');
        $orders = Order::query()->whereNotNull('selling_page_id')->whereNotNull('paid_at')
            ->selectRaw('selling_page_id, COUNT(*) as orders')->groupBy('selling_page_id')->pluck('orders', 'selling_page_id');

        return Inertia::render('SellingPages/Index', [
            'pages' => SellingPage::query()->with('product:id,name')->latest('id')->get()->map(function (SellingPage $page) use ($views, $orders) {
                $viewCount = (int) ($views[$page->id] ?? 0);
                $orderCount = (int) ($orders[$page->id] ?? 0);

                return [
                    'id' => $page->id,
                    'name' => $page->name,
                    'slug' => $page->slug,
                    'status' => $page->status,
                    'product' => $page->product?->name,
                    'views' => $viewCount,
                    'orders' => $orderCount,
                    // Share of views that ended in a paid order.
                    'conversion' => $viewCount > 0 ? round($orderCount / $viewCount * 100, 1) : null,
                    'url' => $page->url(),
                    'hasUnpublishedChanges' => $page->isPublished() && $page->draft_blocks != $page->published_blocks,
                ];
            }),
            'products' => $this->productOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'start' => ['required', Rule::in(['template', 'blank'])],
        ], ['product_id.required' => 'Choose the product this page sells.']);

        $product = Product::query()->with(['images', 'variants'])->findOrFail($data['product_id']);

        $page = DB::transaction(function () use ($data, $product) {
            $page = SellingPage::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'product_id' => $product->id,
                'status' => SellingPage::DRAFT,
                'draft_blocks' => $data['start'] === 'template' ? Blocks::starter($product) : [Blocks::make('order')],
            ]);

            // One pack to start from: the product itself at its catalog price.
            $offer = $page->offers()->create(['label' => $product->name, 'price_cents' => $product->price_cents, 'is_default' => true, 'position' => 0]);
            $offer->items()->create(['product_id' => $product->id, 'variant_id' => $product->variants->first()?->id, 'quantity' => 1]);

            return $page;
        });

        $this->activity->log('create', 'selling_pages', $page, $page->name);

        return redirect()->route('admin.selling-pages.edit', $page)->with('success', 'Page created as a draft.');
    }

    public function edit(SellingPage $page): Response
    {
        $page->load(['offers.items', 'product:id,name,status']);
        $blocks = Blocks::normalize($page->draft_blocks ?? []);

        $mediaIds = [];
        array_walk_recursive($blocks, function ($value, $key) use (&$mediaIds) {
            if (in_array($key, ['image_id', 'poster_id', 'video_id'], true) && $value) {
                $mediaIds[] = $value;
            }
        });
        foreach ($blocks as $block) {
            array_push($mediaIds, ...($block['data']['image_ids'] ?? []));
        }

        return Inertia::render('SellingPages/Edit', [
            'page' => [
                'id' => $page->id,
                'name' => $page->name,
                'slug' => $page->slug,
                'productId' => $page->product_id,
                'status' => $page->status,
                'publishedAt' => $page->published_at?->toIso8601String(),
                'url' => $page->url(),
                'previewUrl' => $page->url().'?preview=1',
                'hasUnpublishedChanges' => $page->isPublished() && $blocks != Blocks::normalize($page->published_blocks),
            ],
            'blocks' => $blocks,
            'offers' => $page->offers->map(fn ($offer) => [
                'id' => $offer->id,
                'label' => $offer->label,
                'price' => number_format($offer->price_cents / 100, 2, '.', ''),
                'note' => (string) $offer->note,
                'is_default' => $offer->is_default,
                'items' => $offer->items->map(fn ($item) => ['product_id' => $item->product_id, 'variant_id' => $item->variant_id, 'quantity' => $item->quantity])->all(),
            ])->all(),
            'library' => Blocks::library(),
            'products' => $this->productOptions(),
            'media' => Media::query()->whereIn('id', array_unique($mediaIds))->get()->map->toAdminArray()->keyBy('id'),
            'reviews' => Review::query()->approved()->where('product_id', $page->product_id)->latest('id')->limit(200)->get()
                ->map(fn (Review $r) => ['id' => $r->id, 'label' => $r->display_name.' · '.$r->rating.'/5 · '.Str::limit($r->body, 60)]),
            'problems' => $this->problems($page, $blocks),
        ]);
    }

    /** Save the draft: settings, packs and content. Customers keep seeing the published version. */
    public function update(Request $request, SellingPage $page): RedirectResponse
    {
        $request->merge(['slug' => Str::slug((string) $request->input('slug'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', Rule::unique('selling_pages', 'slug')->ignore($page->id)],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'blocks' => ['present', 'array', 'max:60'],
            'offers' => ['required', 'array', 'min:1', 'max:4'],
            'offers.*.label' => ['required', 'string', 'max:80'],
            'offers.*.price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'offers.*.note' => ['nullable', 'string', 'max:120'],
            'offers.*.is_default' => ['boolean'],
            'offers.*.items' => ['required', 'array', 'min:1', 'max:20'],
            'offers.*.items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'offers.*.items.*.variant_id' => ['nullable', 'integer'],
            'offers.*.items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ], [
            'slug.required' => 'Enter the page address.',
            'slug.unique' => 'Another selling page already uses this address.',
            'offers.required' => 'Add at least one pack.',
            'offers.max' => 'A page can have up to four packs.',
            'offers.*.label.required' => 'Give the pack a name.',
            'offers.*.price.required' => 'Enter the pack price.',
            'offers.*.items.required' => 'Add at least one product to the pack.',
        ]);

        // Every item must name a variant that belongs to its product, when the product has variants.
        $variants = Variant::query()->whereIn('product_id', collect($data['offers'])->flatMap(fn ($o) => array_column($o['items'], 'product_id'))->unique())
            ->get(['id', 'product_id'])->groupBy('product_id');
        foreach ($data['offers'] as $o => $offer) {
            foreach ($offer['items'] as $i => $item) {
                $own = $variants->get($item['product_id'], collect())->pluck('id');
                $variantId = $item['variant_id'] ?? null;
                if ($own->isNotEmpty() && ! $own->contains($variantId)) {
                    throw ValidationException::withMessages(["offers.{$o}.items.{$i}.variant_id" => 'Choose which option of this product is in the pack.']);
                }
                if ($own->isEmpty() && $variantId) {
                    $data['offers'][$o]['items'][$i]['variant_id'] = null;
                }
            }
        }

        DB::transaction(function () use ($page, $data) {
            $page->update([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'product_id' => $data['product_id'],
                'draft_blocks' => Blocks::normalize($data['blocks']),
            ]);

            $defaultSet = false;
            $page->offers()->delete();
            foreach (array_values($data['offers']) as $position => $row) {
                $isDefault = ! $defaultSet && ($row['is_default'] ?? false);
                $defaultSet = $defaultSet || $isDefault;
                $offer = $page->offers()->create([
                    'label' => $row['label'],
                    'price_cents' => Money::toCents($row['price']),
                    'note' => ($row['note'] ?? '') !== '' ? $row['note'] : null,
                    'is_default' => $isDefault,
                    'position' => $position,
                ]);
                foreach (array_values($row['items']) as $index => $item) {
                    $offer->items()->create(['product_id' => $item['product_id'], 'variant_id' => $item['variant_id'] ?? null, 'quantity' => $item['quantity'], 'position' => $index]);
                }
            }
            if (! $defaultSet) {
                $page->offers()->orderBy('position')->limit(1)->update(['is_default' => true]);
            }
        });

        $this->activity->log('edit', 'selling_pages', $page, $page->name);

        return back()->with('success', 'Draft saved.');
    }

    public function publish(SellingPage $page): RedirectResponse
    {
        $blocks = Blocks::normalize($page->draft_blocks ?? []);
        $problems = $this->problems($page, $blocks);
        if ($problems !== []) {
            return back()->with('error', 'This page cannot be published yet. '.implode(' ', $problems));
        }

        DB::transaction(function () use ($page, $blocks) {
            $page->update(['status' => SellingPage::PUBLISHED, 'published_blocks' => $blocks, 'published_at' => now()]);
            $page->versions()->create([
                'blocks' => $blocks,
                'offers' => $page->offers()->with('items')->get()->toArray(),
                'published_by' => Auth::guard('admin')->id(),
                'published_at' => now(),
            ]);
            // Keep the last ten published versions.
            $keep = $page->versions()->limit(SellingPage::KEEP_VERSIONS)->pluck('id');
            $page->versions()->whereNotIn('id', $keep)->delete();
        });

        $this->activity->log('publish', 'selling_pages', $page, $page->name);

        return back()->with('success', 'Published. The page is live.');
    }

    public function unpublish(SellingPage $page): RedirectResponse
    {
        $page->update(['status' => SellingPage::DRAFT]);
        $this->activity->log('publish', 'selling_pages', $page, $page->name, ['status' => ['published', 'draft']]);

        return back()->with('success', 'Unpublished. The page address now shows "not found".');
    }

    public function destroy(SellingPage $page): RedirectResponse
    {
        $this->activity->log('delete', 'selling_pages', $page, $page->name);
        // Free the address for a new page; past orders keep pointing at this one.
        $page->update(['status' => SellingPage::DRAFT, 'slug' => $page->slug.'-deleted-'.$page->id]);
        $page->delete();

        return redirect()->route('admin.selling-pages.index')->with('success', "{$page->name} deleted.");
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    private function problems(SellingPage $page, array $blocks): array
    {
        $problems = Blocks::problems($blocks);
        $page->loadMissing(['offers.items', 'product']);

        if (! $page->product || $page->product->status !== 'active') {
            $problems[] = 'The linked product is not active. Activate it or link another product.';
        }
        if ($page->offers->isEmpty() || $page->offers->contains(fn ($offer) => $offer->items->isEmpty())) {
            $problems[] = 'Add at least one pack, and put at least one product in every pack.';
        }

        return $problems;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'page';
        $slug = $base;
        $i = 2;
        while (SellingPage::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** @return list<array{id:int, name:string, price:string, variants:list<array{id:int, label:string}>}> */
    private function productOptions(): array
    {
        return Product::query()->where('status', '!=', 'archived')->with('variants.optionValues')->orderBy('name')->limit(1000)->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => number_format($p->price_cents / 100, 2, '.', ''),
                'variants' => $p->variants->map(fn (Variant $v) => ['id' => $v->id, 'label' => $v->label() ?: 'Default'])->all(),
            ])->all();
    }
}
