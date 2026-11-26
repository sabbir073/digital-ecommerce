<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Selling-page checkouts have no cart, so an unpaid order is found again by this per-visitor key.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('checkout_key', 80)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('checkout_key');
        });
    }
};
