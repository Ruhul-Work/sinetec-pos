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
        Schema::create('sale_taxes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sale_id')->index('sale_id');
            $table->unsignedBigInteger('tax_id')->nullable()->index('fk_sale_taxes_tax');
            $table->string('tax_name', 150)->nullable();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('taxable_amount', 14)->default(0);
            $table->decimal('tax_amount', 14)->default(0);
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_taxes');
    }
};
