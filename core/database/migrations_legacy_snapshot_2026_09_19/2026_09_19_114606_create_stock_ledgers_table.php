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
        Schema::create('stock_ledgers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('txn_date');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id')->nullable()->index('sl_wh_idx');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('ref_type', 50)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->enum('direction', ['IN', 'OUT']);
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 12)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index('sl_created_by_fk');
            $table->timestamps();

            $table->index(['branch_id', 'warehouse_id'], 'sl_branch_wh_idx');
            $table->index(['product_id', 'warehouse_id', 'txn_date'], 'sl_prod_wh_date_idx');
            $table->index(['ref_type', 'ref_id'], 'sl_ref_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_ledgers');
    }
};
