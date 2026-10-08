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
        Schema::create('upazilas', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('upazila_district_id')->nullable()->index('upazilas_dist_id');
            $table->string('upazila_name', 25);
            $table->string('upazila_bn_name', 100)->nullable();
            $table->string('upazila_url', 50)->nullable();
            $table->boolean('is_trash')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('upazilas');
    }
};
