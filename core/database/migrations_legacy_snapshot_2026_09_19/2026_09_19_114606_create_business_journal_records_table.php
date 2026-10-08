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
        Schema::create('business_journal_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('business_journal_id')->index('idx_bjr_bj');
            $table->enum('payment_type', ['Incoming', 'Outgoing']);
            $table->date('date');
            $table->unsignedBigInteger('account_id')->index('idx_bjr_account');
            $table->decimal('amount', 15);
            $table->text('narration')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable()->index('idx_bjr_journal_entry');
            $table->unsignedBigInteger('branch_id')->nullable()->index('idx_bjr_branch');
            $table->unsignedBigInteger('created_by')->nullable()->index('idx_bjr_created_by');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_journal_records');
    }
};
