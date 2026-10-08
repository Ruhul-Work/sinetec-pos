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
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('reference_no', 50)->nullable();
            $table->unsignedBigInteger('from_warehouse_id')->index('from_warehouse_id');
            $table->unsignedBigInteger('from_branch_id')->nullable()->index('st_from_branch_fk');
            $table->unsignedBigInteger('to_warehouse_id')->index('to_warehouse_id');
            $table->unsignedBigInteger('to_branch_id')->nullable()->index('st_to_branch_fk');
            $table->dateTime('transfer_date');
            $table->enum('status', ['DRAFT', 'POSTED', 'CANCELLED'])->default('DRAFT');
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
        Schema::dropIfExists('stock_transfers');
    }
};
