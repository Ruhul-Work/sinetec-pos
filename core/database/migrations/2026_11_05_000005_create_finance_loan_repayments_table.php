<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_loan_repayments', function (Blueprint $t) {
            $t->id();
            $t->string('repayment_no', 50);
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignId('loan_receipt_id')->constrained('finance_loan_receipts')->restrictOnDelete();
            $t->foreignId('cash_bank_account_id')->constrained('accounts')->restrictOnDelete();
            $t->date('repayment_date');
            $t->decimal('amount', 20, 4);
            $t->string('reference_no', 100)->nullable();
            $t->text('note')->nullable();
            $t->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['branch_id', 'repayment_no']);
            $t->index(['loan_receipt_id', 'status'], 'loan_repayment_receipt_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_loan_repayments');
    }
};
