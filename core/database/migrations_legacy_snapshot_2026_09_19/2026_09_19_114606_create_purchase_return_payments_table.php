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
        Schema::create('purchase_return_payments', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('purchase_return_id')->nullable();
            $table->bigInteger('supplier_id')->nullable();
            $table->bigInteger('branch_id')->nullable();
            $table->integer('amount');
            $table->string('method', 20)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_return_payments');
    }
};
