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
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sale_id')->nullable()->index('sale_id');
            $table->string('reference_no', 50)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable()->index('customer_id');
            $table->unsignedBigInteger('branch_id')->nullable()->index('fk_sale_returns_branch');
            $table->unsignedBigInteger('warehouse_id')->nullable()->index('fk_sale_returns_warehouse');
            $table->date('return_date')->nullable();
            $table->decimal('total_refund', 14)->default(0);
            $table->string('status', 30)->nullable()->default('pending');
            $table->unsignedBigInteger('created_by')->nullable()->index('fk_sale_returns_created_by');
            $table->unsignedBigInteger('approved_by')->nullable()->index('fk_sale_returns_approved_by');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
