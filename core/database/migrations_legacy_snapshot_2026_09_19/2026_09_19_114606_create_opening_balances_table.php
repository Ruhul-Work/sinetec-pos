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
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('fiscal_year_id')->index('fk_opening_fiscal');
            $table->unsignedBigInteger('branch_id')->index('fk_opening_branch');
            $table->unsignedBigInteger('account_id')->index('fk_opening_account');
            $table->decimal('amount', 15)->default(0);
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'branch_id', 'account_id'], 'uq_opening_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
