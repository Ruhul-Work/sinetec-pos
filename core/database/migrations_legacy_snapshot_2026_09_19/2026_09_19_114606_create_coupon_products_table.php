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
        Schema::create('coupon_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('coupon_id')->nullable();
            $table->integer('product_id')->nullable();
            $table->timestamps();

            $table->unique(['coupon_id', 'product_id'], 'uq_coupon_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupon_products');
    }
};
