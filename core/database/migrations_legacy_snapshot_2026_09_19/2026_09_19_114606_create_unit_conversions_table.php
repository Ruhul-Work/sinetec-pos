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
        Schema::create('unit_conversions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('from_unit_id');
            $table->unsignedBigInteger('to_unit_id')->index('fk_uc_to_unit');
            $table->decimal('factor', 18, 6);
            $table->timestamps();

            $table->unique(['from_unit_id', 'to_unit_id'], 'uq_from_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_conversions');
    }
};
