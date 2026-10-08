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
        Schema::create('offer_discounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('offer_id')->index('idx_offer');
            $table->enum('discount_type', ['percentage', 'fixed'])->nullable()->default('percentage');
            $table->decimal('discount_value', 10);
            $table->decimal('max_discount_amount', 10)->nullable();
            $table->decimal('min_order_amount', 10)->nullable()->default(0);
            $table->enum('applies_to', ['all_products', 'specific_products', 'specific_categories'])->nullable()->default('all_products');
            $table->integer('max_usage_total')->nullable();
            $table->integer('max_usage_per_user')->nullable()->default(1);
            $table->integer('usage_count')->nullable()->default(0);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offer_discounts');
    }
};
