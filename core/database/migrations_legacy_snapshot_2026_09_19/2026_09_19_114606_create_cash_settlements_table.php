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
        Schema::create('cash_settlements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_id')->index('idx_branch');
            $table->date('settlement_date');
            $table->decimal('cash_amount', 15)->default(0);
            $table->decimal('bkash_amount', 15)->default(0);
            $table->decimal('card_amount', 15)->default(0);
            $table->unsignedBigInteger('journal_entry_id')->nullable()->index('idx_journal');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();

            $table->unique(['branch_id', 'settlement_date'], 'uniq_branch_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_settlements');
    }
};
