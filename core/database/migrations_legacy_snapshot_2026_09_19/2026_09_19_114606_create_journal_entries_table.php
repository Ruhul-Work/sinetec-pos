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
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('voucher_no', 50);
            $table->unsignedBigInteger('voucher_type_id')->index('fk_journal_voucher');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('fiscal_year_id')->index('fk_journal_fiscal');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_ref_id')->nullable();
            $table->date('entry_date');
            $table->text('narration')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'entry_date'], 'idx_journal_branch_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
