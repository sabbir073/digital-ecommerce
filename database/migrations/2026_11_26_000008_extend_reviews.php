<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            // A review with no product is a review of the store as a whole.
            $table->unsignedBigInteger('product_id')->nullable()->change();
            // One review per customer per product per order: each bought line can be reviewed once.
            $table->foreignId('order_item_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_id');
        });
    }
};
