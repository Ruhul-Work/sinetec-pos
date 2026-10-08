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
        Schema::create('offer_discount_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('offer_discount_id');
            $table->unsignedBigInteger('product_id')->index('product_id');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['offer_discount_id', 'product_id'], 'unique_discount_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offer_discount_products');
    }
};
