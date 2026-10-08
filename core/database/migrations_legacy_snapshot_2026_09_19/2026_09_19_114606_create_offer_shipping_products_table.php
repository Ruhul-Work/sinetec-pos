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
        Schema::create('offer_shipping_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('offer_shipping_id');
            $table->unsignedBigInteger('product_id')->index('product_id');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['offer_shipping_id', 'product_id'], 'unique_shipping_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offer_shipping_products');
    }
};
