<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HomeSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Services\ActivityLogger;
use App\Services\MediaService;
use App\Services\Reviews\ReviewSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Review moderation, hand-entered and imported reviews, and the review settings (PRD section 12). */
class ReviewController extends Controller
{
    public const CSV_COLUMNS = ['product_slug', 'rating', 'title', 'body', 'display_name', 'country_code', 'date', 'email', 'order_number'];

    public function __construct(private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $filters = [
            'status' => in_array($request->query('status'), [...Review::STATUSES, 'reported'], true) ? $request->query('status') : '',
            'rating' => in_array((int) $request->query('rating'), [1, 2, 3, 4, 5], true) ? (int) $request->query('rating') : null,
            'product' => $request->query('product') === 'store' ? 'store' : ((int) $request->query('product') ?: null),
            'country' => isset(config('countries')[strtoupper((string) $request->query('country'))]) ? strtoupper((string) $request->query('country')) : '',
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
            'search' => trim((string) $request->query('search')),
        ];

        $reviews = Review::query()
            ->with(['product:id,name,slug', 'photos', 'order:id,number', 'reports' => fn ($q) => $q->whereNull('resolved_at')->latest('id')])
            ->when(in_array($filters['status'], Review::STATUSES, true), fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['status'] === 'reported', fn ($q) => $q->whereHas('reports', fn ($r) => $r->whereNull('resolved_at')))
            ->when($filters['rating'], fn ($q, $rating) => $q->where('rating', $rating))
            ->when($filters['product'] === 'store', fn ($q) => $q->whereNull('product_id'))
            ->when(is_int($filters['product']), fn ($q) => $q->where('product_id', $filters['product']))
            ->when($filters['country'] !== '', fn ($q) => $q->where('country_code', $filters['country']))
            ->when($filters['from'], fn ($q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'], fn ($q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['search']).'%';
                $q->where(fn ($w) => $w->where('body', 'like', $like)->orWhere('title', 'like', $like)->orWhere('display_name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->latest('id')->paginate(25)->withQueryString()
            ->through(fn (Review $r) => [
                'id' => $r->id,
                'product' => $r->product ? ['id' => $r->product->id, 'name' => $r->product->name] : null,
                'isStore' => $r->isStoreReview(),
                'rating' => $r->rating,
                'title' => $r->title,
                'body' => $r->body,
                'name' => $r->display_name,
                'email' => $r->email,
                'country' => $r->country_code ? strtoupper($r->country_code) : null,
                'countryName' => $r->countryName(),
                'status' => $r->status,
                'verified' => $r->verified,
                'order' => $r->order ? ['id' => $r->order->id, 'number' => $r->order->number] : null,
                'source' => $r->source,
                'sourceLabel' => $r->source_label,
                'pinned' => $r->pinned,
                'helpful' => $r->helpful_count,
                'reply' => $r->store_reply,
                'photos' => $r->photos->map(fn ($m) => ['id' => $m->id, 'url' => $m->sizeUrl(320), 'full' => $m->url()])->all(),
                'reports' => $r->reports->map(fn ($report) => ['id' => $report->id, 'reason' => $report->reason, 'at' => $report->created_at?->toIso8601String()])->all(),
                'writtenAt' => $r->writtenAt()?->toIso8601String(),
            ]);

        $counts = Review::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('Reviews/Index', [
            'reviews' => $reviews,
            'filters' => $filters,
            'counts' => [
                'all' => (int) $counts->sum(),
                'pending' => (int) ($counts[Review::PENDING] ?? 0),
                'approved' => (int) ($counts[Review::APPROVED] ?? 0),
                'rejected' => (int) ($counts[Review::REJECTED] ?? 0),
                'spam' => (int) ($counts[Review::SPAM] ?? 0),
                'reported' => Review::query()->whereHas('reports', fn ($r) => $r->whereNull('resolved_at'))->count(),
            ],
            'products' => Product::query()->orderBy('name')->limit(1000)->get(['id', 'name'])->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name]),
            'countries' => config('countries'),
            'csvColumns' => self::CSV_COLUMNS,
        ]);
    }

    /** Moderate one review. The reviewer's own words are never changed here. */
    public function update(Request $request, Review $review): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Review::STATUSES)],
            'reply' => ['nullable', 'string', 'max:2000'],
            'pinned' => ['required', 'boolean'],
            'country' => ['nullable', Rule::in(array_keys(config('countries')))],
            'resolve_reports' => ['boolean'],
        ]);

        $reply = trim((string) ($data['reply'] ?? ''));
        $before = $review->only(['status', 'pinned', 'country_code']);
        $review->update([
            'status' => $data['status'],
            'pinned' => $data['pinned'],
            'country_code' => $data['country'] ?? null,
            'store_reply' => $reply !== '' ? $reply : null,
            'replied_at' => $reply === '' ? null : ($reply !== (string) $review->store_reply ? now() : $review->replied_at),
        ]);
        if ($data['resolve_reports'] ?? false) {
            $review->reports()->whereNull('resolved_at')->update(['resolved_at' => now()]);
        }

        $this->activity->log('edit', 'reviews', $review, $this->label($review), ['from' => $before, 'to' => $review->only(['status', 'pinned', 'country_code'])]);

        return back()->with('success', 'Review saved.');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'delete'])],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);
        if ($data['action'] === 'delete') {
            abort_unless(Auth::guard('admin')->user()?->allows('reviews', 'delete'), 403);
        }

        // One at a time, so each change recalculates the product's average rating.
        $reviews = Review::query()->whereIn('id', $data['ids'])->get();
        foreach ($reviews as $review) {
            match ($data['action']) {
                'approve' => $review->update(['status' => Review::APPROVED]),
                'reject' => $review->update(['status' => Review::REJECTED]),
                'delete' => $this->remove($review),
            };
        }
        $this->activity->log($data['action'] === 'delete' ? 'delete' : 'edit', 'reviews', null, $reviews->count().' reviews', ['action' => $data['action']]);

        $done = ['approve' => 'approved', 'reject' => 'rejected', 'delete' => 'deleted'][$data['action']];

        return back()->with('success', $reviews->count().' '.Str::plural('review', $reviews->count())." {$done}.");
    }

    /** Add a genuine review collected elsewhere. It carries a source label. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'min:2', 'max:5000'],
            'display_name' => ['required', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:255'],
            'country' => ['nullable', Rule::in(array_keys(config('countries')))],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'source_label' => ['required', 'string', 'max:60'],
            'order_number' => ['nullable', 'string', 'max:40'],
            'status' => ['required', Rule::in([Review::APPROVED, Review::PENDING])],
        ], [
            'source_label.required' => 'Say where this review was collected, for example "Collected by email".',
            'body.required' => 'Enter the review text exactly as the customer wrote it.',
        ]);

        $order = $this->matchOrder($data['order_number'] ?? null, $data['product_id'] ?? null);
        if (($data['order_number'] ?? '') !== '' && ! $order) {
            return back()->withErrors(['order_number' => 'No paid order with this number contains this product.'])->withInput();
        }

        $review = Review::create($this->attributes($data, $order, 'manual', $data['source_label'], $data['status']));
        $this->activity->log('create', 'reviews', $review, $this->label($review), ['source' => $data['source_label']]);

        return back()->with('success', 'Review added.');
    }

    /** Import genuine reviews collected on another platform. */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'source_label' => ['required', 'string', 'max:60'],
            'status' => ['required', Rule::in([Review::APPROVED, Review::PENDING])],
        ], ['source_label.required' => 'Say where these reviews come from, for example "Imported from Etsy".']);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => Str::lower(trim((string) $h)), fgetcsv($handle) ?: []);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? ''); // Excel BOM
        if (array_diff(['rating', 'body', 'display_name'], $header) !== []) {
            fclose($handle);

            return back()->with('error', 'The CSV needs at least the columns "rating", "body" and "display_name". Download the sample file to see the format.');
        }

        $products = Product::query()->pluck('id', 'slug');
        $countries = config('countries');
        $created = 0;
        $errors = [];
        $line = 1;

        DB::transaction(function () use ($handle, $header, $products, $countries, $request, &$created, &$errors, &$line) {
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                $data = [];
                foreach ($header as $i => $column) {
                    $data[$column] = isset($row[$i]) ? trim((string) $row[$i]) : '';
                }

                $slug = $data['product_slug'] ?? '';
                if ($slug !== '' && ! isset($products[$slug])) {
                    $errors[] = "Line {$line}: no product has the slug \"{$slug}\".";

                    continue;
                }
                if (! ctype_digit($data['rating']) || (int) $data['rating'] < 1 || (int) $data['rating'] > 5) {
                    $errors[] = "Line {$line}: rating must be a whole number from 1 to 5.";

                    continue;
                }
                if ($data['body'] === '' || $data['display_name'] === '') {
                    $errors[] = "Line {$line}: body and display_name are required.";

                    continue;
                }
                $country = strtoupper($data['country_code'] ?? '');
                if ($country !== '' && ! isset($countries[$country])) {
                    $errors[] = "Line {$line}: \"{$country}\" is not a two-letter country code.";

                    continue;
                }
                $date = null;
                if (($data['date'] ?? '') !== '') {
                    try {
                        $date = Carbon::parse($data['date']);
                    } catch (Throwable) {
                        $errors[] = "Line {$line}: the date could not be read. Use YYYY-MM-DD.";

                        continue;
                    }
                }
                $productId = $slug !== '' ? $products[$slug] : null;
                $order = $this->matchOrder($data['order_number'] ?? null, $productId);

                Review::create($this->attributes([
                    'product_id' => $productId,
                    'rating' => (int) $data['rating'],
                    'title' => Str::limit($data['title'] ?? '', 120, ''),
                    'body' => Str::limit($data['body'], 5000, ''),
                    'display_name' => Str::limit($data['display_name'], 60, ''),
                    'email' => filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
                    'country' => $country ?: null,
                    'date' => $date,
                ], $order, 'imported', $request->input('source_label'), $request->input('status')));
                $created++;
            }
        });
        fclose($handle);

        $this->activity->log('create', 'reviews', null, 'CSV import', ['created' => $created, 'skipped' => count($errors), 'source' => $request->input('source_label')]);

        $summary = "{$created} ".Str::plural('review', $created).' imported.';
        if ($errors !== []) {
            $more = count($errors) > 5 ? ' And '.(count($errors) - 5).' more.' : '';

            return back()->with('error', "{$summary} ".count($errors).' skipped. '.implode(' ', array_slice($errors, 0, 5)).$more);
        }

        return back()->with('success', $summary);
    }

    public function sample(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::CSV_COLUMNS);
            fclose($out);
        }, 'reviews-import-format.csv', ['Content-Type' => 'text/csv']);
    }

    public function removePhoto(Review $review, int $media, MediaService $files): RedirectResponse
    {
        $photo = $review->photos()->where('media.id', $media)->firstOrFail();
        $review->photos()->detach($photo->id);
        $files->delete($photo);
        $this->activity->log('edit', 'reviews', $review, $this->label($review), ['removed_photo' => $photo->id]);

        return back()->with('success', 'Photo removed.');
    }

    public function destroy(Review $review): RedirectResponse
    {
        $this->activity->log('delete', 'reviews', $review, $this->label($review));
        $this->remove($review);

        return back()->with('success', 'Review deleted.');
    }

    public function settings(ReviewSettings $settings): Response
    {
        return Inertia::render('Reviews/Settings', [
            'settings' => $settings->all() + [
                'has_turnstile_secret' => $settings->turnstileSecret() !== null,
                'home_section' => (bool) HomeSection::query()->where('type', 'store_reviews')->value('enabled'),
            ],
        ]);
    }

    public function saveSettings(Request $request, ReviewSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'who_may_review' => ['required', Rule::in(['anyone', 'verified'])],
            'auto_approve_verified' => ['required', 'boolean'],
            'hold_links' => ['required', 'boolean'],
            'blocked_words' => ['present', 'array', 'max:200'],
            'blocked_words.*' => ['nullable', 'string', 'max:60'],
            'request_enabled' => ['required', 'boolean'],
            'request_delay_days' => ['required', 'integer', 'min:0', 'max:90'],
            'show_flags' => ['required', 'boolean'],
            'home_section' => ['required', 'boolean'],
            'home_min_rating' => ['required', 'integer', 'between:1,5'],
            'captcha_enabled' => ['required', 'boolean'],
            'turnstile_site_key' => ['nullable', 'string', 'max:120'],
            'turnstile_secret' => ['nullable', 'string', 'max:200'],
        ]);

        $data['blocked_words'] = array_values(array_unique(array_filter(array_map(fn ($w) => Str::lower(trim((string) $w)), $data['blocked_words']))));
        $data['turnstile_site_key'] = trim((string) ($data['turnstile_site_key'] ?? ''));
        if (($data['turnstile_secret'] ?? '') !== '') {
            $settings->saveTurnstileSecret($data['turnstile_secret']);
        }
        if ($data['captcha_enabled'] && ($data['turnstile_site_key'] === '' || $settings->turnstileSecret() === null)) {
            return back()->withErrors(['captcha_enabled' => 'Enter the Turnstile site key and secret key before switching the captcha on.']);
        }

        $settings->save($data);
        HomeSection::query()->updateOrCreate(['type' => 'store_reviews'], ['enabled' => $data['home_section']]);
        $this->activity->log('edit', 'reviews', null, 'Review settings');

        return back()->with('success', 'Review settings saved.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Order $order, string $source, string $label, string $status): array
    {
        return [
            'product_id' => $data['product_id'] ?? null,
            'order_id' => $order?->id,
            'user_id' => $order?->user_id,
            'display_name' => $data['display_name'],
            'email' => ($data['email'] ?? null) ? Str::lower($data['email']) : null,
            'country_code' => $data['country'] ?? null,
            'rating' => $data['rating'],
            'title' => ($data['title'] ?? '') !== '' ? $data['title'] : null,
            'body' => $data['body'],
            'status' => $status,
            // The badge is only for reviews matched to a real, paid order.
            'verified' => $order !== null,
            'source' => $source,
            'source_label' => $label,
            'reviewed_at' => ($data['date'] ?? null) ? Carbon::parse($data['date']) : null,
        ];
    }

    private function matchOrder(?string $number, ?int $productId): ?Order
    {
        $number = trim((string) $number);
        if ($number === '') {
            return null;
        }

        return Order::query()->where('number', $number)->whereNotNull('paid_at')
            ->when($productId, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('product_id', $productId)))
            ->first();
    }

    private function remove(Review $review): void
    {
        $files = app(MediaService::class);
        foreach ($review->photos as $photo) {
            $review->photos()->detach($photo->id);
            $files->delete($photo);
        }
        $review->delete();
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function label(Review $review): string
    {
        return "Review by {$review->display_name}";
    }
}
