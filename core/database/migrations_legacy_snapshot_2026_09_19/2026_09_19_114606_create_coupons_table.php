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
        Schema::create('coupons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('user_id')->nullable();
            $table->enum('coupon_type', ['product', 'bill', 'user'])->default('product');
            $table->text('type_details')->nullable();
            $table->string('title')->nullable();
            $table->string('code');
            $table->decimal('discount');
            $table->enum('discount_type', ['flat', 'percentage'])->default('flat');
            $table->integer('min_buy')->nullable()->default(0);
            $table->integer('max_discount')->nullable()->default(0);
            $table->integer('is_valid_first_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('user_type')->nullable();
            $table->integer('stock')->nullable();
            $table->integer('individual_max_use');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
