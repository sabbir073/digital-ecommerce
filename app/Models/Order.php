<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\URL;

class Order extends Model
{
    public const PENDING = 'pending_payment';

    public const PAID = 'paid';

    public const FAILED = 'payment_failed';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    public const PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATUS_LABELS = [
        self::PENDING => 'Awaiting payment',
        self::PAID => 'Paid',
        self::FAILED => 'Payment failed',
        self::CANCELLED => 'Cancelled',
        self::REFUNDED => 'Refunded',
        self::PARTIALLY_REFUNDED => 'Partly refunded',
    ];

    protected $fillable = [
        'number', 'user_id', 'cart_id', 'checkout_key', 'fingerprint', 'email', 'name', 'status', 'source', 'selling_page_id', 'offer_id', 'coupon_id', 'coupon_code',
        'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents', 'refunded_cents', 'currency', 'billing_country',
        'billing_postcode', 'stripe_tax_calculation_id', 'stripe_tax_transaction_id', 'notes', 'phone', 'customer_note', 'terms_accepted_at', 'ip', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'purchase_event_sent_at' => 'datetime', 'terms_accepted_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderByDesc('id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function sellingPage(): BelongsTo
    {
        return $this->belongsTo(SellingPage::class)->withTrashed();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Paid at some point, so downloads were granted (a refund may have revoked them since). */
    public function wasPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    /** Link to the confirmation page. Signed, so a guest can open their own order and nobody else's. */
    public function url(): string
    {
        return URL::signedRoute('order.show', ['number' => $this->number]);
    }

    public function record(string $type, ?string $note = null, ?int $adminUserId = null): void
    {
        $this->events()->create(['type' => $type, 'note' => $note ? mb_substr($note, 0, 250) : null, 'admin_user_id' => $adminUserId]);
    }
}
