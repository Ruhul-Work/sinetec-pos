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
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('permission_id')->index();
            $table->tinyInteger('can_view')->nullable();
            $table->tinyInteger('can_add')->nullable();
            $table->tinyInteger('can_edit')->nullable();
            $table->tinyInteger('can_delete')->nullable();
            $table->tinyInteger('can_export')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'permission_id'], 'user_permissions_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
