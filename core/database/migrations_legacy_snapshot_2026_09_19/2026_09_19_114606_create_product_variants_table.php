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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('color_id')->nullable()->index('pv_color_fk');
            $table->unsignedBigInteger('size_id')->nullable()->index('pv_size_fk');
            $table->string('sku', 191)->unique();
            $table->string('barcode', 191)->nullable()->unique();
            $table->decimal('price', 12)->nullable();
            $table->decimal('cost_price', 12)->nullable();
            $table->string('name', 255)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['product_id', 'is_active'], 'pv_product_active_idx');
            $table->unique(['product_id', 'color_id', 'size_id'], 'pv_uniq_combo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
