<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_loan_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('loan_no', 50);
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->enum('loan_type', ['DIRECTOR_LOAN', 'PARTNER_LOAN']);
            $table->foreignId('cash_bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('loan_account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('receipt_date');
            $table->decimal('amount', 20, 4);
            $table->string('reference_no', 100)->nullable();
            $table->text('note')->nullable();
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'loan_no']);
            $table->index(['branch_id', 'receipt_date', 'status'], 'loan_receipts_branch_date_status_idx');
            $table->index(['loan_account_id', 'status'], 'loan_receipts_account_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_loan_receipts');
    }
};
