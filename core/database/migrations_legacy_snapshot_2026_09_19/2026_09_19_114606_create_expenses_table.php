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
        Schema::create('expenses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_id')->index('idx_expenses_branch_id');
            $table->string('name', 255);
            $table->string('expense_no', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('description')->nullable();
            $table->longText('memo_image')->nullable();
            $table->date('expense_date')->index('idx_expenses_expense_date');
            $table->decimal('total_amount', 15)->default(0);
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->unsignedBigInteger('created_by')->nullable()->index('idx_expenses_created_by');
            $table->timestamps();
            $table->string('invoice_no', 50)->nullable();
            $table->dateTime('posted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
