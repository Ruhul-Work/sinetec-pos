<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('offer_gifts', function (Blueprint $table) {
            $table->foreign(['offer_id'], 'offer_gifts_ibfk_1')->references(['id'])->on('offers')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['buy_product_id'], 'offer_gifts_ibfk_2')->references(['id'])->on('products')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['free_product_id'], 'offer_gifts_ibfk_3')->references(['id'])->on('products')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offer_gifts', function (Blueprint $table) {
            $table->dropForeign('offer_gifts_ibfk_1');
            $table->dropForeign('offer_gifts_ibfk_2');
            $table->dropForeign('offer_gifts_ibfk_3');
        });
    }
};
