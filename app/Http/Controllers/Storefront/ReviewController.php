<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\ReviewRequest;
use App\Models\ReviewVote;
use App\Models\Setting;
use App\Services\Integrations\Tracking;
use App\Services\Reviews\Captcha;
use App\Services\Reviews\ReviewListing;
use App\Services\Reviews\ReviewService;
use App\Services\Reviews\ReviewSettings;
use App\Services\Seo\SeoBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Public side of reviews: the store reviews page, writing a review, helpful votes and reports (PRD section 12). */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewService $reviews,
        private readonly ReviewSettings $settings,
        private readonly Captcha $captcha,
    ) {}

    /** Reviews of the store as a whole. */
    public function index(Request $request, ReviewListing $listing): View
    {
        $store = Setting::get('store.name', config('app.name'));
        $filtered = collect($request->query())->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();

        return view('storefront.reviews', $listing->build(null, $request) + [
            'form' => self::formContext($request, null),
            'seo' => app(SeoBuilder::class)->page(t('Store reviews'), t('What customers say about buying from :store.', ['store' => $store]), ['canonical' => route('reviews'), 'noindex' => $filtered]),
        ]);
    }

    public function storeForProduct(Request $request, string $slug): RedirectResponse
    {
        $product = Product::query()->visible()->where('slug', $slug)->firstOrFail();
        $user = Auth::guard('web')->user();

        return $this->take($request, $product, $this->reviews->reviewableItem($user, $product), null, null);
    }

    public function storeForStore(Request $request): RedirectResponse
    {
        return $this->take($request, null, null, $this->reviews->reviewableOrder(Auth::guard('web')->user()), null);
    }

    /** The page behind the link in the review-request email: no sign-in needed. */
    public function invited(Request $request, string $token): View
    {
        $invitation = ReviewRequest::query()->where('token', $token)->with('orderItem.order.items', 'orderItem.product.images')->firstOrFail();
        $item = $invitation->orderItem;
        abort_unless($item?->order?->paid_at && $item->product, 404);

        $others = ReviewRequest::query()->whereIn('order_item_id', $item->order->items->pluck('id'))->whereKeyNot($invitation->id)
            ->whereNull('used_at')->with('orderItem.product')->get()->filter(fn ($r) => $r->orderItem?->product);

        return view('storefront.review-invited', [
            'invitation' => $invitation,
            'item' => $item,
            'product' => $item->product,
            'done' => $invitation->used_at !== null || Review::query()->where('order_item_id', $item->id)->exists(),
            'others' => $others,
            'form' => [
                'action' => route('review.invited.store', $token),
                'verified' => true,
                'name' => $item->order->name,
                'email' => null,
                'country' => $item->order->billing_country,
                'lockCountry' => (bool) $item->order->billing_country,
                'askEmail' => false,
                'allowed' => true,
            ] + self::captchaContext($this->settings),
        ]);
    }

    public function storeInvited(Request $request, string $token): RedirectResponse
    {
        $invitation = ReviewRequest::query()->where('token', $token)->with('orderItem.order', 'orderItem.product')->firstOrFail();
        $item = $invitation->orderItem;
        abort_unless($item?->order?->paid_at && $item->product, 404);

        if ($invitation->used_at || Review::query()->where('order_item_id', $item->id)->exists()) {
            return back()->with('error', t('You have already reviewed this item. Thank you.'));
        }

        return $this->take($request, $item->product, $item, null, $invitation);
    }

    public function helpful(Request $request, Review $review): RedirectResponse
    {
        abort_unless($review->status === Review::APPROVED, 404);

        try {
            ReviewVote::create(['review_id' => $review->id, 'visitor_id' => $this->visitor($request)]);
            $review->increment('helpful_count');
            $message = 'Thanks. Your vote was counted.';
        } catch (UniqueConstraintViolationException) {
            // One vote per visitor per review.
            $message = 'You have already marked this review as helpful.';
        }

        return redirect()->to(url()->previous().'#review-'.$review->id)->with('success', $message);
    }

    public function report(Request $request, Review $review): RedirectResponse
    {
        abort_unless($review->status === Review::APPROVED, 404);
        $data = $request->validate(['reason' => ['required', Rule::in(array_keys(self::REPORT_REASONS))], 'details' => ['nullable', 'string', 'max:300']]);
        $visitor = $this->visitor($request);

        if (! ReviewReport::query()->where('review_id', $review->id)->where('visitor_id', $visitor)->exists()) {
            ReviewReport::create([
                'review_id' => $review->id,
                'visitor_id' => $visitor,
                'reason' => trim(self::REPORT_REASONS[$data['reason']].(($data['details'] ?? '') !== '' ? ': '.$data['details'] : '')),
            ]);
        }

        return redirect()->to(url()->previous().'#review-'.$review->id)->with('success', t('Thanks. Our team will look at this review.'));
    }

    public const REPORT_REASONS = [
        'spam' => 'Spam or advertising',
        'offensive' => 'Offensive or abusive',
        'fake' => 'Not a genuine review',
        'private' => 'Contains private information',
        'other' => 'Something else',
    ];

    private function take(Request $request, ?Product $product, ?OrderItem $item, ?Order $order, ?ReviewRequest $invitation): RedirectResponse
    {
        $verified = $item !== null || $order !== null;
        $back = fn () => redirect()->to(Str::before(url()->previous(), '#').'#write-review');

        if (! $verified && ! $this->reviews->open()) {
            return $back()->with('error', t('Only customers who bought from us can write a review. Sign in to the account you ordered with, or use the link in your review email.'));
        }

        $user = Auth::guard('web')->user();
        $validator = Validator::make($request->all(), [
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'min:20', 'max:5000'],
            'display_name' => ['required', 'string', 'max:60'],
            'email' => [$invitation || $user ? 'nullable' : 'required', 'email', 'max:255'],
            'country_code' => ['nullable', Rule::in(array_keys(config('countries')))],
            'photos' => ['nullable', 'array', 'max:'.ReviewService::MAX_PHOTOS],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'rating.required' => t('Choose a star rating.'),
            'body.required' => t('Write your review.'),
            'body.min' => t('Please write at least 20 characters.'),
            'display_name.required' => t('Enter the name to show with your review.'),
            'email.required' => t('Enter your email. It is not shown publicly.'),
            'photos.max' => t('You can add up to 5 photos.'),
            'photos.*.image' => t('Photos must be JPG, PNG or WebP images.'),
            'photos.*.mimes' => t('Photos must be JPG, PNG or WebP images.'),
            'photos.*.max' => t('Each photo can be up to 8 MB.'),
        ]);
        if ($validator->fails()) {
            return $back()->withErrors($validator)->withInput($request->except('photos'));
        }
        $data = $validator->validated();
        $email = Str::lower($data['email'] ?? $user?->email ?? '');
        $data['email'] = $email !== '' ? $email : null;

        // Spam protection: a limit per IP address and per email, a hidden field people never fill in, and the optional captcha.
        $limits = ['review-ip|'.$request->ip() => 5] + ($email !== '' ? ['review-email|'.$email => 3] : []);
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return $back()->withInput($request->except('photos'))->with('error', t('You have sent several reviews in a short time. Please try again in an hour.'));
            }
        }
        if (! $this->captcha->passes($request->input('cf-turnstile-response'), $request->ip())) {
            return $back()->withInput($request->except('photos'))->with('error', t('Please complete the "I am human" check and send your review again.'));
        }
        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 3600);
        }

        $spam = trim((string) $request->input('website')) !== '';

        try {
            $review = $this->reviews->submit($product, $data, $spam ? [] : $request->file('photos', []), $user, $request->ip(), $item, $order, $invitation, $spam);
        } catch (UniqueConstraintViolationException) {
            return $back()->with('error', t('You have already reviewed this item. Thank you.'));
        }

        if (! $spam) {
            Tracking::flash(Tracking::event('submit_review', ['rating' => (int) $review->rating] + ($product ? ['items' => [Tracking::item($product, null, 1, $product->fromPriceCents())]] : [])));
        }

        $message = $review->status === Review::APPROVED
            ? 'Thank you. Your review is now live.'
            : 'Thank you. Your review will appear once our team has checked it.';

        return redirect()->to(Str::before(url()->previous(), '#').'#reviews')->with('success', $message);
    }

    /** A stable, anonymous id for this browser, so a visitor can vote or report once per review. */
    private function visitor(Request $request): string
    {
        $id = $request->cookie('visitor');
        if (! is_string($id) || strlen($id) < 20) {
            $id = Str::random(40);
            Cookie::queue('visitor', $id, 60 * 24 * 365);
        }

        return hash('sha256', $id);
    }

    /**
     * What the "Write a review" form needs: where it posts, what is pre-filled and whether this visitor may write one.
     *
     * @return array<string, mixed>
     */
    public static function formContext(Request $request, ?Product $product): array
    {
        $service = app(ReviewService::class);
        $settings = app(ReviewSettings::class);
        $user = Auth::guard('web')->user();

        $item = $product ? $service->reviewableItem($user, $product) : null;
        $order = $product ? $item?->order : $service->reviewableOrder($user);
        $lastCountry = $user ? Order::query()->where('user_id', $user->id)->whereNotNull('billing_country')->latest('id')->value('billing_country') : null;
        // Behind Cloudflare the visitor's country arrives in a header; without it nothing is pre-selected.
        $ipCountry = strtoupper((string) $request->header('CF-IPCountry'));

        return [
            'action' => $product ? route('product.reviews.store', $product->slug) : route('reviews.store'),
            'verified' => $order !== null,
            'allowed' => $order !== null || $service->open(),
            'name' => $user?->name,
            'email' => $user?->email,
            'askEmail' => $user === null,
            'country' => $order?->billing_country ?: ($lastCountry ?: (isset(config('countries')[$ipCountry]) ? $ipCountry : null)),
            'lockCountry' => (bool) $order?->billing_country,
        ] + self::captchaContext($settings);
    }

    /** @return array{captchaKey: ?string} */
    private static function captchaContext(ReviewSettings $settings): array
    {
        return ['captchaKey' => $settings->captchaActive() ? $settings->get('turnstile_site_key') : null];
    }
}
