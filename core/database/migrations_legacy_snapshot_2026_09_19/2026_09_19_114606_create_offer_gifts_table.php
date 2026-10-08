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
        Schema::create('offer_gifts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('offer_id')->index('idx_offer');
            $table->unsignedBigInteger('buy_product_id')->index('idx_buy_product');
            $table->integer('buy_quantity')->nullable()->default(1);
            $table->enum('gift_type', ['same_product', 'different_product']);
            $table->unsignedBigInteger('free_product_id')->nullable()->index('free_product_id');
            $table->integer('free_quantity')->nullable()->default(1);
            $table->decimal('min_purchase_amount', 10)->nullable()->default(0);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offer_gifts');
    }
};
