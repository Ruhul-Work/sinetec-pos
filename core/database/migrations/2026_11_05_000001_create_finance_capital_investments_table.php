<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL may retain the table if an index declaration fails during a first run.
        // Repair that empty, incomplete migration state without dropping any table.
        if (Schema::hasTable('finance_capital_investments')) {
            Schema::table('finance_capital_investments', function (Blueprint $table) {
                $table->index(['branch_id', 'investment_date', 'status'], 'capital_investments_branch_date_status_idx');
                $table->index(['capital_account_id', 'status'], 'capital_investments_account_status_idx');
            });

            return;
        }

        Schema::create('finance_capital_investments', function (Blueprint $table) {
            $table->id();
            $table->string('investment_no', 50);
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->enum('investment_type', ['OWNER_CAPITAL', 'PARTNER_CAPITAL']);
            $table->foreignId('cash_bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('capital_account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('investment_date');
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

            $table->unique(['branch_id', 'investment_no']);
            $table->index(['branch_id', 'investment_date', 'status'], 'capital_investments_branch_date_status_idx');
            $table->index(['capital_account_id', 'status'], 'capital_investments_account_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_capital_investments');
    }
};
