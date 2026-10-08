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
        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('count_id')->index('count_id');
            $table->unsignedBigInteger('product_id')->index('product_id');
            $table->decimal('counted_qty', 18, 3);
            $table->decimal('system_qty', 18, 3);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
    }
};
