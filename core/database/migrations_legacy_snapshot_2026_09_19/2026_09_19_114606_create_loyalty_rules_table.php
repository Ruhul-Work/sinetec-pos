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
        Schema::create('loyalty_rules', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->string('name', 150);
            $table->decimal('earn_amount', 10, 0)->nullable();
            $table->integer('earn_points')->nullable();
            $table->integer('redeem_points')->nullable();
            $table->integer('redeem_amount')->nullable();
            $table->integer('min_redeem_points')->nullable();
            $table->integer('max_redeem_points')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_rules');
    }
};
