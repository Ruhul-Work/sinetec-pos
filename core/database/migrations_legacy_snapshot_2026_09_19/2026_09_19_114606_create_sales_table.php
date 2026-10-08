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
        Schema::create('sales', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('invoice_no', 60)->unique('sale_number');
            $table->unsignedBigInteger('branch_id')->nullable()->index('branch_id');
            $table->unsignedBigInteger('warehouse_id')->nullable()->index('warehouse_id');
            $table->unsignedBigInteger('customer_id')->nullable()->index('customer_id');
            $table->unsignedBigInteger('user_id')->nullable()->index('user_id');
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->string('coupon_code', 50)->nullable();
            $table->decimal('coupon_discount', 14)->default(0);
            $table->string('pos_session_id', 255)->nullable();
            $table->string('sale_type', 30)->nullable()->default('retail');
            $table->enum('source', ['pos', 'facebook', 'instagram', 'tiktok', 'whatsapp', 'imo'])->nullable();
            $table->enum('status', ['delivered', 'hold', 'order', 'pending', 'cancel', 'draft', 'void'])->nullable()->default('delivered');
            $table->decimal('subtotal', 14)->default(0);
            $table->decimal('discount', 14)->default(0);
            $table->integer('point_discount')->nullable();
            $table->decimal('tax_amount', 14)->default(0);
            $table->decimal('shipping_charge', 14)->default(0);
            $table->decimal('total', 14)->default(0);
            $table->decimal('paid_amount', 14)->default(0);
            $table->decimal('due_amount', 14)->default(0);
            $table->string('payment_status', 20)->nullable()->default('due');
            $table->text('sale_note')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->useCurrentOnUpdate()->nullable();

            $table->index(['invoice_no'], 'sale_number_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
