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
        Schema::create('branch_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('account_id')->index('fk_branch_accounts_account');
            $table->boolean('is_active')->nullable()->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['branch_id', 'account_id'], 'uq_branch_account');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_accounts');
    }
};
