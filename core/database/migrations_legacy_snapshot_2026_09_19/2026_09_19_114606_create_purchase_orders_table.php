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
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable()->index('fk_po_warehouse');
            $table->string('po_number', 100)->index('idx_purchase_orders_po_number');
            $table->enum('status', ['draft', 'received', 'partially_received', 'closed', 'cancelled'])->default('draft');
            $table->date('order_date')->nullable();
            $table->date('expected_date')->nullable();
            $table->char('currency', 3)->nullable()->default('BDT');
            $table->decimal('subtotal', 14)->nullable()->default(0);
            $table->decimal('tax_amount', 14)->nullable()->default(0);
            $table->decimal('shipping_amount', 14)->nullable()->default(0);
            $table->decimal('total_amount', 14)->nullable()->default(0);
            $table->decimal('paid_amount', 14)->default(0);
            $table->decimal('outstanding_amount', 14)->default(0);
            $table->enum('payment_status', ['unpaid', 'partially_paid', 'paid'])->default('unpaid');
            $table->text('notes')->nullable();
            $table->string('discount_type', 20)->nullable();
            $table->decimal('discount_value', 12)->default(0);
            $table->decimal('discount_amount', 14)->default(0);
            $table->string('purchase_invoice', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();

            $table->index(['supplier_id', 'status'], 'idx_purchase_orders_supplier_status');
            $table->unique(['po_number'], 'po_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
