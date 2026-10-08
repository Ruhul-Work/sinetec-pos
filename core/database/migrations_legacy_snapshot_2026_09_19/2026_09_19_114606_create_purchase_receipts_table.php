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
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id')->index('fk_pr_supplier');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('warehouse_id')->index('fk_pr_warehouses');
            $table->unsignedBigInteger('purchase_order_id')->nullable()->index('idx_purchase_receipts_po');
            $table->dateTime('receipt_date');
            $table->string('invoice_no', 64)->nullable();
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
        Schema::dropIfExists('purchase_receipts');
    }
};
