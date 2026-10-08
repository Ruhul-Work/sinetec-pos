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
        Schema::table('offer_shippings', function (Blueprint $table) {
            $table->foreign(['offer_id'], 'offer_shippings_ibfk_1')->references(['id'])->on('offers')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offer_shippings', function (Blueprint $table) {
            $table->dropForeign('offer_shippings_ibfk_1');
        });
    }
};
