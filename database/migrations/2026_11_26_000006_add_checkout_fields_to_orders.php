<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The cart an unpaid order was started from, and a hash of what was being bought,
            // so pressing Pay twice continues the same order instead of starting a second one.
            $table->foreignId('cart_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('fingerprint', 64)->nullable()->after('cart_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cart_id');
            $table->dropColumn('fingerprint');
        });
    }
};
