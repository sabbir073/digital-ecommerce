<?php

namespace App\Services\Reviews;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Support\Facades\Cache;

/** Keeps the average rating and review count of products (and of the store) in step with approved reviews. */
class RatingCalculator
{
    public function refresh(?int $productId): void
    {
        if ($productId === null) {
            Cache::forget('reviews.store.summary');

            return;
        }

        $row = Review::query()->approved()->where('product_id', $productId)->selectRaw('COUNT(*) as total, AVG(rating) as average')->first();
        Product::query()->whereKey($productId)->update([
            'rating_count' => (int) $row->total,
            'rating_average' => $row->total > 0 ? round((float) $row->average, 2) : 0,
        ]);
    }

    /**
     * Average, count and the number of reviews at each star level.
     *
     * @return array{average: float, count: int, stars: array<int, int>}
     */
    public function summary(?int $productId): array
    {
        $build = function () use ($productId) {
            $counts = Review::query()->approved()
                ->when($productId === null, fn ($q) => $q->whereNull('product_id'), fn ($q) => $q->where('product_id', $productId))
                ->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->pluck('total', 'rating');
            $stars = [];
            $sum = 0;
            foreach ([5, 4, 3, 2, 1] as $star) {
                $stars[$star] = (int) ($counts[$star] ?? 0);
                $sum += $star * $stars[$star];
            }
            $count = array_sum($stars);

            return ['average' => $count > 0 ? round($sum / $count, 2) : 0.0, 'count' => $count, 'stars' => $stars];
        };

        return $productId === null ? Cache::remember('reviews.store.summary', 3600, $build) : $build();
    }
}
