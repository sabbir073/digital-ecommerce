<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selling_pages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index(); // draft, published
            $table->json('draft_blocks')->nullable();
            $table->json('published_blocks')->nullable();
            $table->json('pixel_ids')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('selling_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selling_page_id')->constrained()->cascadeOnDelete();
            $table->json('blocks');
            $table->json('offers')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('published_at');
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selling_page_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('price_cents');
            $table->string('note')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selling_page_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unique(['selling_page_id', 'date']);
        });

        Schema::create('page_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('blocks');
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type', 20); // percent, fixed
            $table->unsignedBigInteger('value');
            $table->unsignedBigInteger('min_spend_cents')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('applies_to_selling_pages')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->index();
            $table->string('name')->nullable();
            $table->string('status', 30)->default('pending_payment')->index();
            $table->string('source', 20)->default('cart'); // cart, selling_page
            $table->foreignId('selling_page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code')->nullable();
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('refunded_cents')->default(0);
            $table->char('currency', 3)->default('usd');
            $table->char('billing_country', 2)->nullable();
            $table->string('billing_postcode', 20)->nullable();
            $table->string('stripe_tax_calculation_id')->nullable();
            $table->string('stripe_tax_transaction_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('purchase_event_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('variant_label')->nullable();
            $table->string('sku')->nullable();
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_total_cents');
            $table->timestamps();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('note')->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_payment_intent_id')->unique();
            $table->string('idempotency_key')->unique();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('amount_cents');
            $table->string('card_brand', 30)->nullable();
            $table->char('card_last4', 4)->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_refund_id')->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason')->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_dispute_id')->unique();
            $table->string('status', 40);
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestamps();
            $table->unique(['coupon_id', 'order_id']);
        });

        Schema::create('download_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->unsignedInteger('downloads_used')->default(0);
            $table->unsignedInteger('max_downloads')->default(5);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('download_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('download_grant_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamp('downloaded_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_logs');
        Schema::dropIfExists('download_grants');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('page_templates');
        Schema::dropIfExists('page_views');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('selling_page_versions');
        Schema::dropIfExists('selling_pages');
    }
};
