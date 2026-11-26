<?php

namespace App\Services\Reviews;

use App\Models\MediaFolder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Takes in customer reviews: who may write one, whether it counts as a verified buyer, and its first status. */
class ReviewService
{
    public const MAX_PHOTOS = 5;

    public function __construct(private readonly ReviewSettings $settings, private readonly MediaService $media) {}

    /**
     * The paid order line a signed-in customer can still review for this product, if any.
     * It is what makes a review "Verified buyer".
     */
    public function reviewableItem(?User $user, Product $product): ?OrderItem
    {
        if (! $user) {
            return null;
        }

        return OrderItem::query()
            ->where('product_id', $product->id)
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id)->whereNotNull('paid_at'))
            ->whereNotIn('id', Review::query()->whereNotNull('order_item_id')->select('order_item_id'))
            ->oldest('id')->first();
    }

    /** The paid order a signed-in customer can attach a store review to, if any. */
    public function reviewableOrder(?User $user): ?Order
    {
        if (! $user) {
            return null;
        }

        return Order::query()->where('user_id', $user->id)->whereNotNull('paid_at')
            ->whereNotIn('id', Review::query()->ofStore()->whereNotNull('order_id')->select('order_id'))
            ->latest('id')->first();
    }

    public function open(): bool
    {
        return $this->settings->get('who_may_review') === 'anyone';
    }

    /**
     * @param  array{rating:int, title?:?string, body:string, display_name:string, email?:?string, country_code?:?string}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function submit(?Product $product, array $data, array $photos, ?User $user, ?string $ip, ?OrderItem $item = null, ?Order $order = null, ?ReviewRequest $invitation = null, bool $spam = false): Review
    {
        $order = $item?->order ?? $order;
        $verified = $order !== null && $order->paid_at !== null;

        $review = DB::transaction(function () use ($product, $data, $user, $ip, $item, $order, $verified, $invitation, $spam) {
            $review = Review::create([
                'product_id' => $product?->id,
                'order_id' => $order?->id,
                'order_item_id' => $item?->id,
                'user_id' => $user?->id ?? $order?->user_id,
                'display_name' => $data['display_name'],
                'email' => isset($data['email']) ? Str::lower($data['email']) : $order?->email,
                // A verified buyer's country is the one on the order.
                'country_code' => ($verified ? $order->billing_country : null) ?: (($data['country_code'] ?? null) ?: null),
                'rating' => $data['rating'],
                'title' => ($data['title'] ?? '') !== '' ? $data['title'] : null,
                'body' => $data['body'],
                'status' => $spam ? Review::SPAM : $this->firstStatus($data, $verified),
                'verified' => $verified,
                'source' => 'customer',
                'ip' => $ip,
            ]);
            $invitation?->update(['used_at' => now()]);

            return $review;
        });

        if (! $spam) {
            $this->attachPhotos($review, $photos);
        }

        return $review;
    }

    /** @param array<string, mixed> $data */
    private function firstStatus(array $data, bool $verified): string
    {
        $text = Str::lower(($data['title'] ?? '').' '.$data['body'].' '.$data['display_name']);

        if ($this->settings->get('hold_links') && preg_match('~https?://|www\.|\b[a-z0-9-]+\.(com|net|org|io|co|ru|cn|info|biz|xyz|shop|store)\b~i', $text)) {
            return Review::PENDING;
        }
        foreach ($this->settings->get('blocked_words') as $word) {
            if ($word !== '' && str_contains($text, Str::lower($word))) {
                return Review::PENDING;
            }
        }

        return $verified && $this->settings->get('auto_approve_verified') ? Review::APPROVED : Review::PENDING;
    }

    /** @param list<UploadedFile> $photos */
    public function attachPhotos(Review $review, array $photos): void
    {
        if ($photos === []) {
            return;
        }
        $folder = MediaFolder::query()->firstOrCreate(['name' => 'Review photos', 'parent_id' => null]);
        foreach (array_slice(array_values($photos), 0, self::MAX_PHOTOS) as $position => $photo) {
            // Resized and compressed like every other upload.
            $media = $this->media->store($photo, $folder->id, 'Photo from a customer review');
            $review->photos()->attach($media->id, ['position' => $position]);
        }
    }
}
