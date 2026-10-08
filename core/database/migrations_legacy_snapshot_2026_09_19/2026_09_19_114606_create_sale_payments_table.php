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
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sale_id')->index('sale_id');
            $table->unsignedBigInteger('account_id')->nullable()->index('fk_sale_payments_account');
            $table->unsignedBigInteger('payment_type_id')->nullable()->index('payment_type_id');
            $table->enum('payment_type', ['cash', 'bkash', 'card', ''])->nullable()->default('cash');
            $table->decimal('amount', 14)->default(0);
            $table->decimal('change_amount', 14)->nullable();
            $table->string('reference', 255)->nullable();
            $table->timestamp('paid_at')->nullable()->useCurrent();
            $table->string('received_by', 255)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
