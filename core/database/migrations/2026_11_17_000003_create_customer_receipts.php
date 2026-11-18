<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_no', 50)->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->dateTime('receipt_at');
            $table->decimal('amount', 20, 4);
            $table->enum('status', ['POSTED', 'VOID'])->default('POSTED');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'receipt_at', 'status']);
            $table->index(['customer_id', 'receipt_at']);
            $table->index(['sale_id', 'receipt_at']);
        });

        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->foreignId('customer_receipt_id')
                ->nullable()
                ->after('sale_id')
                ->constrained('customer_receipts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_receipt_id');
        });

        Schema::dropIfExists('customer_receipts');
    }
};
