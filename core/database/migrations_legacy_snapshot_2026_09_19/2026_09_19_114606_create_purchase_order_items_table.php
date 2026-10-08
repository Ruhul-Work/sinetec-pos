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
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id')->index('fk_po_items_product');
            $table->string('sku', 100)->nullable();
            $table->text('description')->nullable();
            $table->decimal('unit_cost', 14)->nullable()->default(0);
            $table->unsignedInteger('quantity')->nullable()->default(0);
            $table->unsignedInteger('received_quantity')->nullable()->default(0);
            $table->decimal('line_total', 14)->nullable()->default(0);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();

            $table->index(['purchase_order_id', 'product_id'], 'idx_po_items_po_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
