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
        Schema::create('sale_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sale_id')->index('sale_id');
            $table->unsignedBigInteger('product_id')->index('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable()->index('product_variant_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('unit_price', 14)->default(0);
            $table->decimal('discount_amount', 14)->default(0);
            $table->decimal('tax_amount', 14)->default(0);
            $table->decimal('line_total', 14)->default(0);
            $table->string('lot_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
