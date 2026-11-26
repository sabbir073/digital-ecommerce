<?php

namespace App\Services\Reviews;

use App\Models\Review;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/** The public list of approved reviews for a product or for the store, with its summary, sort and filters. */
class ReviewListing
{
    public const SORTS = ['newest' => 'Newest', 'highest' => 'Highest rating', 'lowest' => 'Lowest rating', 'helpful' => 'Most helpful'];

    public const PER_PAGE = 10;

    public function __construct(private readonly RatingCalculator $ratings) {}

    /**
     * @return array{summary: array{average: float, count: int, stars: array<int, int>}, reviews: LengthAwarePaginator, filters: array{stars: ?int, sort: string, photos: bool, country: ?string}, countries: array<string, string>, filtered: bool}
     */
    public function build(?int $productId, Request $request): array
    {
        $stars = in_array((int) $request->query('stars'), [1, 2, 3, 4, 5], true) ? (int) $request->query('stars') : null;
        $sort = array_key_exists((string) $request->query('sort'), self::SORTS) ? (string) $request->query('sort') : 'newest';
        $photos = $request->boolean('photos');
        $country = strtoupper((string) $request->query('country'));
        $country = isset(config('countries')[$country]) ? $country : null;

        $base = fn () => Review::query()->approved()
            ->when($productId === null, fn ($q) => $q->whereNull('product_id'), fn ($q) => $q->where('product_id', $productId));

        $query = $base()->with('photos')
            ->when($stars, fn ($q) => $q->where('rating', $stars))
            ->when($photos, fn ($q) => $q->whereHas('photos'))
            ->when($country, fn ($q) => $q->where('country_code', $country))
            // Pinned reviews stay on top whatever the sort.
            ->orderByDesc('pinned');

        match ($sort) {
            'highest' => $query->orderByDesc('rating'),
            'lowest' => $query->orderBy('rating'),
            'helpful' => $query->orderByDesc('helpful_count'),
            default => null,
        };
        $query->orderByRaw('COALESCE(reviewed_at, created_at) DESC')->orderByDesc('id');

        $codes = $base()->whereNotNull('country_code')->distinct()->pluck('country_code')->map(fn ($c) => strtoupper($c));

        return [
            'summary' => $this->ratings->summary($productId),
            'reviews' => $query->paginate(self::PER_PAGE, ['*'], 'rpage')->withQueryString()->fragment('reviews'),
            'filters' => ['stars' => $stars, 'sort' => $sort, 'photos' => $photos, 'country' => $country],
            'countries' => collect(config('countries'))->only($codes->all())->all(),
            'filtered' => $stars !== null || $photos || $country !== null,
        ];
    }
}
