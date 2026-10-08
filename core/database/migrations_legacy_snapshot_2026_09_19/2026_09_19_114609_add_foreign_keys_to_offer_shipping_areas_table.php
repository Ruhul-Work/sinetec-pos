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
        Schema::table('offer_shipping_areas', function (Blueprint $table) {
            $table->foreign(['offer_shipping_id'], 'offer_shipping_areas_ibfk_1')->references(['id'])->on('offer_shippings')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offer_shipping_areas', function (Blueprint $table) {
            $table->dropForeign('offer_shipping_areas_ibfk_1');
        });
    }
};
