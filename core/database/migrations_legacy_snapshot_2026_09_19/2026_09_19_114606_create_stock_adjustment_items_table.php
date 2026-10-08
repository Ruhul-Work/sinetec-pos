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
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('adjustment_id')->index('adjustment_id');
            $table->unsignedBigInteger('product_id')->index('product_id');
            $table->unsignedBigInteger('warehouse_id')->nullable()->index('warehouse_id');
            $table->unsignedBigInteger('branch_id')->nullable()->index('branch_id');
            $table->enum('direction', ['IN', 'OUT']);
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 12)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
    }
};
