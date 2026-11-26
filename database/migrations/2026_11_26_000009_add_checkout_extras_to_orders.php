<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('phone', 40)->nullable()->after('name');
            $table->text('customer_note')->nullable()->after('notes');
            $table->timestamp('terms_accepted_at')->nullable()->after('customer_note');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['phone', 'customer_note', 'terms_accepted_at']);
        });
    }
};
