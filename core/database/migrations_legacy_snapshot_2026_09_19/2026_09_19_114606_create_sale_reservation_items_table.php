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
        Schema::create('sale_reservation_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('reservation_id')->index('reservation_id');
            $table->unsignedBigInteger('product_id')->index('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable()->index('fk_sale_reservation_items_variant');
            $table->decimal('qty', 12, 3)->default(0);
            $table->decimal('unit_price', 14)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_reservation_items');
    }
};
