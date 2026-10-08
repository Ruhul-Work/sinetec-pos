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
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('purchase_id')->nullable();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('warehouse_id')->nullable()->index('fk_pre_warehouse');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('return_number', 100)->unique('return_number');
            $table->date('return_date')->nullable();
            $table->string('reference', 150)->nullable();
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->decimal('subtotal', 14)->nullable()->default(0);
            $table->integer('discount')->nullable();
            $table->integer('shipping_charge')->nullable();
            $table->decimal('tax_amount', 14)->nullable()->default(0);
            $table->decimal('total_amount', 14)->nullable()->default(0);
            $table->integer('paid_amount')->nullable();
            $table->integer('due_amount')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();

            $table->index(['supplier_id', 'return_date'], 'idx_returns_supplier_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
