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
        Schema::create('offer_shippings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('offer_id')->index('idx_offer');
            $table->enum('free_shipping_on', ['bkash', 'cod', 'both', 'all_methods']);
            $table->enum('shipping_applies_to', ['all_products', 'specific_products'])->nullable()->default('all_products');
            $table->decimal('min_order_amount', 10)->nullable()->default(0);
            $table->enum('applicable_area', ['all', 'inside_dhaka', 'outside_dhaka', 'custom'])->nullable()->default('all');
            $table->decimal('max_shipping_discount', 10)->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offer_shippings');
    }
};
