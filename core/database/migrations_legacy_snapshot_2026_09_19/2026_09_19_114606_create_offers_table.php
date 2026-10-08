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
        Schema::create('offers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 255);
            $table->string('slug', 191)->unique('slug');
            $table->text('description')->nullable();
            $table->string('banner_image', 500)->nullable();
            $table->enum('offer_type', ['gift', 'shipping', 'discount', 'bundle'])->index('idx_offer_type');
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->dateTime('announcement_start_at')->nullable();
            $table->dateTime('announcement_end_at')->nullable();
            $table->boolean('is_active')->nullable()->default(true)->index('idx_active');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();

            $table->index(['start_date', 'end_date'], 'idx_dates');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
