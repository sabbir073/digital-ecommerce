<?php

namespace App\Http\Controllers\Storefront;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Offer;
use App\Models\Order;
use App\Models\SellingPage;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\CouponService;
use App\Services\Payments\PaymentGateway;
use App\Services\Selling\OfferPricing;
use App\Services\Selling\PagePresenter;
use App\Services\Seo\SeoBuilder;
use App\Services\Seo\StructuredData;
use App\Services\StoreSettings;
use App\Support\Blocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The public one-page selling page and its inline checkout (PRD section 4). */
class SellingPageController extends Controller
{
    public function __construct(
        private readonly PagePresenter $presenter,
        private readonly OfferPricing $pricing,
        private readonly CheckoutService $checkout,
        private readonly CouponService $coupons,
        private readonly PaymentGateway $gateway,
    ) {}

    public function show(Request $request, string $slug): Response
    {
        $page = SellingPage::query()->where('slug', $slug)->with('product.images')->firstOrFail();

        // Staff who may see selling pages can preview the unpublished draft.
        $preview = $request->boolean('preview') && Auth::guard('admin')->user()?->allows('selling_pages', 'view');
        abort_unless($preview || $page->isPublished(), 404);
        abort_unless($page->product, 404);

        $blocks = Blocks::normalize($preview ? ($page->draft_blocks ?? $page->published_blocks) : $page->published_blocks);
        $data = $this->presenter->present($page, $blocks);
        $faq = app(StructuredData::class)->faq($blocks);
        $data['seo'] = app(SeoBuilder::class)->for('selling_page', $page, ['json_ld' => array_values(array_filter([app(StructuredData::class)->product($page->product), $faq]))]);

        if (! $preview) {
            $this->countView($request, $page);
        }

        $default = collect($data['offers'])->firstWhere('default', true);
        $coupon = $this->coupon($request, $page);
        $summary = $default ? $this->pricing->summary(Offer::find($default['id']), $coupon) : null;

        // Coming back from a bank's own payment page: show the confirmation for that order.
        $returning = $request->filled('order') && $request->hasValidSignature()
            ? Order::query()->where('number', $request->query('order'))->where('selling_page_id', $page->id)->first()
            : null;

        $ready = $this->gateway->isConfigured();

        return response()->view('selling.page', $data + [
            'page' => $page,
            'product' => $page->product,
            'preview' => $preview,
            'summary' => $summary,
            'coupon' => $summary?->coupon,
            'countries' => config('countries'),
            'ready' => $ready,
            'testMode' => $ready && $this->gateway->isTestMode(),
            'config' => [
                'key' => $this->gateway->publishableKey(),
                'ready' => $ready,
                'preview' => $preview,
                'quoteUrl' => route('selling-page.quote', $page->slug),
                'startUrl' => route('selling-page.start', $page->slug),
                'couponUrl' => route('selling-page.coupon', $page->slug),
                'confirmUrl' => $returning ? $this->confirmUrl($page, $returning) : null,
                'payLabel' => collect($data['blocks'])->firstWhere('type', 'order')['data']['pay_label'] ?? 'Pay',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Totals for the chosen pack, with tax once the country is known. */
    public function quote(Request $request, string $slug): JsonResponse
    {
        $page = $this->page($slug);
        $data = $request->validate([
            'offer_id' => ['required', 'integer'],
            'country' => ['nullable', Rule::in(array_keys(config('countries')))],
            'postcode' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->totals($request, $page, $this->offer($page, (int) $data['offer_id']), $data['country'] ?? null, $data['postcode'] ?? null);
    }

    public function applyCoupon(Request $request, string $slug): JsonResponse
    {
        $page = $this->page($slug);
        $data = $request->validate([
            'offer_id' => ['required', 'integer'],
            'code' => ['nullable', 'string', 'max:60'],
            'country' => ['nullable', Rule::in(array_keys(config('countries')))],
            'postcode' => ['nullable', 'string', 'max:20'],
        ]);
        $offer = $this->offer($page, (int) $data['offer_id']);

        if (trim((string) ($data['code'] ?? '')) === '') {
            $request->session()->forget("selling.{$page->id}.coupon");
        } else {
            try {
                $coupon = $this->coupons->resolve($data['code'], (int) $offer->price_cents, 'selling_page');
            } catch (CheckoutException $e) {
                return response()->json(['errors' => ['coupon' => [$e->getMessage()]]], 422);
            }
            $request->session()->put("selling.{$page->id}.coupon", $coupon->id);
        }

        return $this->totals($request, $page, $offer, $data['country'] ?? null, $data['postcode'] ?? null);
    }

    /** Create (or continue) the unpaid order and hand the browser what it needs to confirm the payment. */
    public function start(Request $request, string $slug): JsonResponse
    {
        $page = $this->page($slug);
        $settings = app(StoreSettings::class);
        [$extraRules, $extraMessages] = $settings->checkoutRules();
        $data = $request->validate([
            'offer_id' => ['required', 'integer'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'name' => ['required', 'string', 'max:120'],
            'country' => ['required', Rule::in(array_keys(config('countries')))],
            'postcode' => [Rule::requiredIf(fn () => in_array($request->input('country'), ['US', 'CA'], true)), 'nullable', 'string', 'max:20'],
        ] + $extraRules, $extraMessages + [
            'email.required' => t('Enter your email so we can send your download links.'),
            'email.email' => t('That email address does not look right.'),
            'name.required' => t('Enter your name.'),
            'country.required' => t('Choose your country.'),
            'postcode.required' => t('Enter your ZIP or postal code.'),
        ]);

        $offer = $this->offer($page, (int) $data['offer_id']);
        $summary = $this->pricing->summary($offer, $this->coupon($request, $page));

        try {
            $result = $this->checkout->place($summary, [
                'email' => Str::lower($data['email']),
                'name' => $data['name'],
                'country' => $data['country'],
                'postcode' => $data['postcode'] ?? null,
            ], [
                'source' => 'selling_page',
                'selling_page_id' => $page->id,
                'offer_id' => $offer->id,
                'checkout_key' => $this->checkoutKey($request, $page),
                'user_id' => Auth::guard('web')->id(),
                'ip' => $request->ip(),
            ]);
        } catch (CheckoutException $e) {
            return response()->json(['errors' => [$e->field => [$e->getMessage()]]], 422);
        }

        $order = $result['order'];
        $order->forceFill($settings->checkoutExtras($data))->save();

        return response()->json([
            'client_secret' => $result['client_secret'],
            'paid' => $result['paid'],
            'total' => (int) $order->total_cents,
            'tax' => (int) $order->tax_cents,
            'confirm_url' => $this->confirmUrl($page, $order),
            'return_url' => URL::signedRoute('selling-page', ['slug' => $page->slug, 'order' => $order->number]),
        ]);
    }

    /** Polled after paying: the confirmation that replaces the order block once Stripe confirms the payment. */
    public function confirmation(string $slug, string $number): JsonResponse
    {
        $page = SellingPage::query()->where('slug', $slug)->firstOrFail();
        $order = Order::query()->where('number', $number)->where('selling_page_id', $page->id)
            ->with(['items.downloads.asset', 'payment'])->firstOrFail();

        if ($order->wasPaid()) {
            return response()->json(['state' => 'paid', 'html' => view('selling.confirmation', ['order' => $order])->render()]);
        }
        if (in_array($order->status, [Order::FAILED, Order::CANCELLED], true)) {
            return response()->json([
                'state' => 'failed',
                'message' => ($order->payment?->failure_message ?: 'The payment did not go through.').' Nothing was charged. You can try again.',
            ]);
        }

        return response()->json(['state' => 'pending']);
    }

    private function totals(Request $request, SellingPage $page, Offer $offer, ?string $country, ?string $postcode): JsonResponse
    {
        $summary = $this->pricing->summary($offer, $this->coupon($request, $page));

        try {
            $quote = $this->checkout->quote($summary, $country, $postcode);
        } catch (CheckoutException $e) {
            return response()->json(['errors' => [$e->field => [$e->getMessage()]]], 422);
        }

        return response()->json([
            'label' => $offer->label,
            'subtotal' => $quote['subtotal'],
            'discount' => $quote['discount'],
            'tax' => $quote['tax'],
            'total' => $quote['total'],
            'coupon' => $summary->coupon?->code,
            'couponProblem' => $summary->couponProblem,
            'soldOut' => $summary->hasIssues() || $summary->isEmpty(),
        ]);
    }

    private function page(string $slug): SellingPage
    {
        return SellingPage::query()->published()->where('slug', $slug)->firstOrFail();
    }

    private function offer(SellingPage $page, int $id): Offer
    {
        return $page->offers()->whereKey($id)->firstOrFail();
    }

    private function coupon(Request $request, SellingPage $page): ?Coupon
    {
        $id = $request->hasSession() ? $request->session()->get("selling.{$page->id}.coupon") : null;

        return $id ? Coupon::find($id) : null;
    }

    /** One key per visitor and page, so pressing Pay twice continues the same unpaid order. */
    private function checkoutKey(Request $request, SellingPage $page): string
    {
        $token = $request->session()->get('selling.visitor');
        if (! $token) {
            $request->session()->put('selling.visitor', $token = Str::random(40));
        }

        return "sp-{$page->id}-{$token}";
    }

    private function confirmUrl(SellingPage $page, Order $order): string
    {
        return URL::signedRoute('selling-page.confirmation', ['slug' => $page->slug, 'number' => $order->number]);
    }

    private function countView(Request $request, SellingPage $page): void
    {
        if (preg_match('/bot|crawl|spider|slurp|preview|monitor/i', (string) $request->userAgent())) {
            return;
        }

        $today = now()->toDateString();
        $key = "selling.{$page->id}.seen";
        $unique = $request->session()->get($key) !== $today;
        $request->session()->put($key, $today);

        DB::table('page_views')->insertOrIgnore(['selling_page_id' => $page->id, 'date' => $today, 'views' => 0, 'unique_visitors' => 0]);
        DB::table('page_views')->where('selling_page_id', $page->id)->where('date', $today)->update([
            'views' => DB::raw('views + 1'),
            'unique_visitors' => DB::raw('unique_visitors + '.($unique ? 1 : 0)),
        ]);
    }
}
