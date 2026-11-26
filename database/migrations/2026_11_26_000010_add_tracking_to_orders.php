<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // What the buyer's browser allowed and carried at checkout, so the Purchase event sent
            // from the server after payment respects their consent and matches their browser.
            $table->json('tracking')->nullable();
            // Set when the Purchase event was handed to the buyer's browser, so a refresh never sends it again.
            $table->timestamp('purchase_tracked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['tracking', 'purchase_tracked_at']);
        });
    }
};
