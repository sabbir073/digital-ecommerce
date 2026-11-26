<?php

namespace App\Services\Customers;

use App\Models\Cart;
use App\Models\ChatConversation;
use App\Models\ContactMessage;
use App\Models\CouponRedemption;
use App\Models\DataDeletionRequest;
use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deletes what identifies one person, by email (PRD section 9: customers can ask for their data to be deleted).
 *
 * Orders stay, because the store's accounts need them, but nothing in them points to the person any more.
 * Reviews stay as published content without the name. The account, chats and messages are removed.
 */
class CustomerEraser
{
    /** Orders of a deleted customer carry an address on this domain, which can never receive email. */
    public const DOMAIN = 'deleted.invalid';

    /** @return array{account:bool, orders:int, reviews:int, conversations:int, messages:int} */
    public function erase(string $email): array
    {
        $email = Str::lower(trim($email));

        return DB::transaction(function () use ($email) {
            $user = User::query()->where('email', $email)->first();
            $orders = Order::query()->where('email', $email)->when($user, fn ($q) => $q->orWhere('user_id', $user->id))->get();

            foreach ($orders as $order) {
                $grants = DownloadGrant::query()->whereIn('order_item_id', $order->items()->pluck('id'));
                DownloadLog::query()->whereIn('download_grant_id', (clone $grants)->pluck('id'))->update(['ip' => null]);
                $grants->whereNull('revoked_at')->update(['revoked_at' => now()]);
                CouponRedemption::query()->where('order_id', $order->id)->update(['email' => "deleted-{$order->id}@".self::DOMAIN]);
                $order->forceFill([
                    'user_id' => null, 'email' => "deleted-{$order->id}@".self::DOMAIN, 'name' => null, 'phone' => null, 'customer_note' => null,
                    'billing_postcode' => null, 'ip' => null, 'tracking' => null, 'fingerprint' => null,
                ])->saveQuietly();
            }

            $reviews = Review::query()->where('email', $email)->when($user, fn ($q) => $q->orWhere('user_id', $user->id))
                ->update(['display_name' => 'Former customer', 'email' => null, 'user_id' => null, 'ip' => null]);

            $conversations = ChatConversation::query()->where('email', $email)->when($user, fn ($q) => $q->orWhere('user_id', $user->id))->get();
            $conversations->each->delete();
            $messages = ContactMessage::query()->where('email', $email)->delete();

            if ($user) {
                Cart::query()->where('user_id', $user->id)->delete();
                // Their sessions end by themselves: the account they point to no longer exists. The sessions table is
                // not touched, because its user_id column is shared with staff sign-ins.
                $user->delete();
            }
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            DataDeletionRequest::query()->where('email', $email)->where('status', DataDeletionRequest::PENDING)
                ->update(['status' => DataDeletionRequest::DONE, 'completed_at' => now(), 'email' => 'deleted@'.self::DOMAIN]);

            return ['account' => (bool) $user, 'orders' => $orders->count(), 'reviews' => $reviews, 'conversations' => $conversations->count(), 'messages' => $messages];
        });
    }
}
