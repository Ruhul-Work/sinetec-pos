<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_cash_bank_transfers', function (Blueprint $t) {
            $t->id();
            $t->string('transfer_no', 50);
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $t->date('transfer_date');
            $t->decimal('amount', 20, 4);
            $t->string('reference_no', 100)->nullable();
            $t->text('note')->nullable();
            $t->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->timestamps();
            $t->unique(['branch_id', 'transfer_no']);
            $t->index(['branch_id', 'transfer_date', 'status'], 'cash_bank_transfer_branch_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cash_bank_transfers');
    }
};
