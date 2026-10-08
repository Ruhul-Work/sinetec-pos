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
        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sale_return_id')->index('sale_return_id');
            $table->unsignedBigInteger('sale_item_id')->nullable()->index('fk_sale_return_items_sale_item');
            $table->unsignedBigInteger('product_id')->index('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable()->index('fk_sale_return_items_variant');
            $table->decimal('qty', 12, 3)->default(0);
            $table->decimal('unit_price', 14)->default(0);
            $table->decimal('refund_amount', 14)->default(0);
            $table->decimal('discount_amount', 14)->default(0);
            $table->string('reason', 255)->nullable();
            $table->boolean('stock_adjusted')->nullable()->default(false);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
    }
};
