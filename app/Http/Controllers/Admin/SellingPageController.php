<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\SellingPage;
use App\Models\SellingPageVersion;
use App\Models\Variant;
use App\Services\ActivityLogger;
use App\Services\Integrations\Integrations;
use App\Services\Payments\PaymentGateway;
use App\Services\Selling\OfferPricing;
use App\Services\Selling\PagePresenter;
use App\Support\Blocks;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Selling pages in admin: the list, page settings, packs, content and publishing (PRD section 5). */
class SellingPageController extends Controller
{
    public function __construct(
        private ActivityLogger $activity,
        private PagePresenter $presenter,
        private OfferPricing $pricing,
        private PaymentGateway $gateway,
    ) {}

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
            'pixels' => collect(Integrations::PAGE_PIXELS)->map(fn (string $field, string $service) => [
                'service' => $service,
                'label' => Integrations::SERVICES[$service]['name'].': '.Integrations::SERVICES[$service]['fields'][$field]['label'],
                'value' => (string) ($page->pixel_ids[$service] ?? ''),
            ])->values(),
            'offers' => $page->offers->map(fn ($offer) => [
                'id' => $offer->id,
                'label' => $offer->label,
                'price' => number_format($offer->price_cents / 100, 2, '.', ''),
                'note' => (string) $offer->note,
                'is_default' => $offer->is_default,
                'items' => $offer->items->map(fn ($item) => ['product_id' => $item->product_id, 'variant_id' => $item->variant_id, 'quantity' => $item->quantity])->all(),
            ])->all(),
            'products' => $this->productOptions(),
            'blockCount' => count($blocks),
            'problems' => $this->problems($page, $blocks),
        ]);
    }

    /** The drag-and-drop builder for the page content (PRD section 5). */
    public function builder(SellingPage $page): Response
    {
        $blocks = Blocks::normalize($page->draft_blocks ?? []);

        return Inertia::render('SellingPages/Builder', [
            'page' => [
                'id' => $page->id,
                'name' => $page->name,
                'status' => $page->status,
                'url' => $page->url(),
                'previewUrl' => $page->url().'?preview=1',
                'canvasUrl' => route('admin.selling-pages.canvas', $page),
                'hasUnpublishedChanges' => $page->isPublished() && $blocks != Blocks::normalize($page->published_blocks),
            ],
            'blocks' => $blocks,
            'library' => Blocks::library(),
            'media' => $this->media($blocks),
            'reviews' => Review::query()->approved()->where(fn ($q) => $q->where('product_id', $page->product_id)->orWhereNull('product_id'))->latest('id')->limit(300)->get()
                ->map(fn (Review $r) => ['id' => $r->id, 'label' => ($r->isStoreReview() ? 'Store review · ' : '').$r->display_name.' · '.$r->rating.'/5 · '.Str::limit($r->body, 60)]),
            'problems' => $this->problems($page, $blocks),
            'versions' => $this->versions($page),
        ]);
    }

    /** The page the builder shows in its canvas: the draft, drawn with the real storefront templates. */
    public function canvas(SellingPage $page): HttpResponse
    {
        abort_unless($page->product, 404);

        return response()
            ->view('selling.page', $this->canvasData($page, Blocks::normalize($page->draft_blocks ?? [])))
            ->header('Cache-Control', 'no-store, private');
    }

    /** Draw the blocks the builder is holding, without saving them, so the canvas follows every edit. */
    public function render(Request $request, SellingPage $page): JsonResponse
    {
        abort_unless($page->product, 404);
        $request->validate(['blocks' => ['present', 'array', 'max:60']]);
        $blocks = Blocks::normalize($request->input('blocks'));

        return response()->json(['html' => view('selling._blocks', $this->canvasData($page, $blocks))->render()]);
    }

    /** Autosave from the builder. Customers keep seeing the published version. */
    public function saveDraft(Request $request, SellingPage $page): JsonResponse
    {
        $request->validate(['blocks' => ['present', 'array', 'max:60']]);
        $blocks = Blocks::normalize($request->input('blocks'));
        $page->update(['draft_blocks' => $blocks]);

        return response()->json([
            'problems' => $this->problems($page, $blocks),
            'hasUnpublishedChanges' => $page->isPublished() && $blocks != Blocks::normalize($page->published_blocks),
        ]);
    }

    /** Put an earlier published version back as the draft. It goes live only when published again. */
    public function restore(SellingPage $page, SellingPageVersion $version): JsonResponse
    {
        abort_unless($version->selling_page_id === $page->id, 404);
        $blocks = Blocks::normalize($version->blocks);
        $page->update(['draft_blocks' => $blocks]);
        $this->activity->log('edit', 'selling_pages', $page, $page->name, ['restored_version' => $version->published_at?->toDateTimeString()]);

        return response()->json([
            'blocks' => $blocks,
            'media' => $this->media($blocks),
            'problems' => $this->problems($page, $blocks),
            'hasUnpublishedChanges' => $page->isPublished() && $blocks != Blocks::normalize($page->published_blocks),
        ]);
    }

    /** A new draft page with the same content and packs. */
    public function duplicate(SellingPage $page): RedirectResponse
    {
        $page->load('offers.items');
        $name = Str::limit($page->name, 113, '').' (copy)';

        $copy = DB::transaction(function () use ($page, $name) {
            $copy = SellingPage::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'product_id' => $page->product_id,
                'status' => SellingPage::DRAFT,
                'draft_blocks' => Blocks::normalize($page->draft_blocks ?? []),
            ]);
            foreach ($page->offers as $offer) {
                $new = $copy->offers()->create($offer->only(['label', 'price_cents', 'note', 'is_default', 'position']));
                foreach ($offer->items as $item) {
                    $new->items()->create($item->only(['product_id', 'variant_id', 'quantity', 'position']));
                }
            }

            return $copy;
        });

        $this->activity->log('create', 'selling_pages', $copy, $copy->name, ['copied_from' => $page->name]);

        return redirect()->route('admin.selling-pages.edit', $copy)->with('success', 'Page duplicated as a draft.');
    }

    /** Save the page settings and packs. The content is saved by the builder. */
    public function update(Request $request, SellingPage $page): RedirectResponse
    {
        $request->merge(['slug' => Str::slug((string) $request->input('slug'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', Rule::unique('selling_pages', 'slug')->ignore($page->id)],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
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

        return back()->with('success', 'Saved.');
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

    /**
     * Everything the storefront templates need to draw the draft inside the builder.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function canvasData(SellingPage $page, array $blocks): array
    {
        $page->loadMissing('product.images');
        $data = $this->presenter->present($page, $blocks, builder: true);
        $default = collect($data['offers'])->firstWhere('default', true);
        $ready = $this->gateway->isConfigured();

        return $data + [
            'page' => $page,
            'product' => $page->product,
            'builder' => true,
            'preview' => false,
            'summary' => $default ? $this->pricing->summary(Offer::find($default['id']), null) : null,
            'coupon' => null,
            'countries' => config('countries'),
            'ready' => $ready,
            'testMode' => $ready && $this->gateway->isTestMode(),
            'config' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return Collection<int, array<string, mixed>>
     */
    private function media(array $blocks)
    {
        $ids = [];
        foreach ($blocks as $block) {
            foreach (['image_id', 'poster_id', 'video_id'] as $key) {
                if (! empty($block['data'][$key])) {
                    $ids[] = $block['data'][$key];
                }
            }
            array_push($ids, ...($block['data']['image_ids'] ?? []));
        }

        return Media::query()->whereIn('id', array_unique($ids))->get()->map->toAdminArray()->keyBy('id');
    }

    /** @return list<array{id:int, publishedAt:?string, by:?string, blocks:int}> */
    private function versions(SellingPage $page): array
    {
        return $page->versions()->with('publisher:id,name')->limit(SellingPage::KEEP_VERSIONS)->get()->map(fn (SellingPageVersion $v) => [
            'id' => $v->id,
            'publishedAt' => $v->published_at?->toIso8601String(),
            'by' => $v->publisher?->name,
            'blocks' => count($v->blocks ?? []),
        ])->all();
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
