<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_capital_withdrawals', function (Blueprint $table) {
            $table->id(); $table->string('withdrawal_no', 50); $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->enum('withdrawal_type', ['OWNER_WITHDRAWAL', 'PARTNER_WITHDRAWAL']);
            $table->foreignId('cash_bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('drawings_account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('withdrawal_date'); $table->decimal('amount', 20, 4); $table->string('reference_no', 100)->nullable(); $table->text('note')->nullable();
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('voided_at')->nullable(); $table->text('void_reason')->nullable(); $table->timestamps();
            $table->unique(['branch_id', 'withdrawal_no']); $table->index(['branch_id', 'withdrawal_date', 'status'], 'capital_withdrawals_branch_date_status_idx'); $table->index(['drawings_account_id', 'status'], 'capital_withdrawals_account_status_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('finance_capital_withdrawals'); }
};
