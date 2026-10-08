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
        Schema::create('warehouses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name', 255);
            $table->string('code', 32)->unique();
            $table->enum('type', ['store', 'showroom', 'returns', 'virtual'])->default('store')->index('warehouses_type_idx');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('phone', 32)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->longText('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'is_active'], 'warehouses_branch_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
