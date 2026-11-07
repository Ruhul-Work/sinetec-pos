<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_fiscal_year_closings', function (Blueprint $table): void {
            $table->id();
            $table->string('closing_no', 50);
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('drawings_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('capital_account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('closing_date');
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

            $table->unique(['branch_id', 'closing_no']);
            $table->index(['fiscal_year_id', 'branch_id', 'status'], 'fiscal_closing_year_branch_status_idx');
            $table->index(['drawings_account_id', 'status'], 'fiscal_closing_drawings_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_fiscal_year_closings');
    }
};
