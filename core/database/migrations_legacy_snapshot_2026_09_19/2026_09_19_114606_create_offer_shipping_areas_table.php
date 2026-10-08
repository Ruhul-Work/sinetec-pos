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
        Schema::create('offer_shipping_areas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('offer_shipping_id')->index('offer_shipping_id');
            $table->unsignedBigInteger('area_id');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offer_shipping_areas');
    }
};
