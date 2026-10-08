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
        Schema::create('sale_invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sale_id')->index('sale_id');
            $table->string('invoice_number', 80)->unique('invoice_number');
            $table->timestamp('issued_at')->nullable()->useCurrent();
            $table->date('due_date')->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->unsignedBigInteger('issued_by')->nullable()->index('fk_sale_invoices_issued_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_invoices');
    }
};
