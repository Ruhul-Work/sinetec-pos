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
        Schema::create('business_journals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 255);
            $table->string('category', 100)->nullable();
            $table->date('date');
            $table->unsignedBigInteger('branch_id')->nullable()->index('idx_bj_branch');
            $table->unsignedBigInteger('created_by')->nullable()->index('idx_bj_created_by');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_journals');
    }
};
