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
        Schema::table('offer_discount_categories', function (Blueprint $table) {
            $table->foreign(['offer_discount_id'], 'offer_discount_categories_ibfk_1')->references(['id'])->on('offer_discounts')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['category_id'], 'offer_discount_categories_ibfk_2')->references(['id'])->on('categories')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offer_discount_categories', function (Blueprint $table) {
            $table->dropForeign('offer_discount_categories_ibfk_1');
            $table->dropForeign('offer_discount_categories_ibfk_2');
        });
    }
};
