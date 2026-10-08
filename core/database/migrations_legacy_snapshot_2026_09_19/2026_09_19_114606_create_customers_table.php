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
        Schema::create('customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 255);
            $table->string('slug', 255)->nullable();
            $table->string('email', 191)->nullable()->unique('email');
            $table->string('phone', 20)->unique('phone');
            $table->string('alternate_phone', 20)->nullable();
            $table->string('address', 255);
            $table->integer('postal_code')->nullable();
            $table->longText('image')->nullable();
            $table->boolean('is_active');
            $table->date('birth_date')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
