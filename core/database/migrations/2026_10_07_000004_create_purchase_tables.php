<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order', function (Blueprint $table) {
            $table->id(); $table->string('order_no', 50)->unique(); $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete(); $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete(); $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete(); $table->date('order_date'); $table->date('expected_date')->nullable(); $table->enum('status', ['DRAFT','APPROVED','PARTIAL','RECEIVED','CANCELLED'])->default('DRAFT'); $table->decimal('subtotal', 20, 4)->default(0); $table->decimal('discount', 20, 4)->default(0); $table->decimal('tax', 20, 4)->default(0); $table->decimal('total', 20, 4)->default(0); $table->text('note')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('approved_at')->nullable(); $table->timestamps(); $table->index(['supplier_id','order_date','status']);
        });
        $this->createMixedItemTable('purchase_order_item', 'purchase_order_id', 'purchase_order', true);

        Schema::create('purchase_receipt', function (Blueprint $table) {
            $table->id(); $table->string('receipt_no', 50)->unique(); $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_order')->restrictOnDelete(); $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete(); $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete(); $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete(); $table->date('receipt_date'); $table->string('supplier_invoice_no', 80)->nullable(); $table->enum('status', ['DRAFT','POSTED','VOID'])->default('DRAFT'); $table->decimal('subtotal', 20, 4)->default(0); $table->decimal('discount', 20, 4)->default(0); $table->decimal('tax', 20, 4)->default(0); $table->decimal('total', 20, 4)->default(0); $table->text('note')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('posted_at')->nullable(); $table->timestamps(); $table->index(['supplier_id','receipt_date','status']);
        });
        $this->createMixedItemTable('purchase_receipt_item', 'purchase_receipt_id', 'purchase_receipt', false, 'purchase_order_item_id', 'purchase_order_item');

        Schema::create('purchase_return', function (Blueprint $table) {
            $table->id(); $table->string('return_no', 50)->unique(); $table->foreignId('purchase_receipt_id')->nullable()->constrained('purchase_receipt')->restrictOnDelete(); $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete(); $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete(); $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete(); $table->date('return_date'); $table->enum('status', ['DRAFT','POSTED','VOID'])->default('DRAFT'); $table->decimal('total', 20, 4)->default(0); $table->text('reason')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('posted_at')->nullable(); $table->timestamps();
        });
        $this->createMixedItemTable('purchase_return_item', 'purchase_return_id', 'purchase_return', false, 'purchase_receipt_item_id', 'purchase_receipt_item');

        Schema::create('purchase_payment', function (Blueprint $table) {
            $table->id(); $table->string('payment_no', 50)->unique(); $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete(); $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete(); $table->foreignId('purchase_receipt_id')->nullable()->constrained('purchase_receipt')->restrictOnDelete(); $table->foreignId('payment_type_id')->constrained('payment_types')->restrictOnDelete(); $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete(); $table->date('payment_date'); $table->decimal('amount', 20, 4); $table->string('reference_no', 100)->nullable(); $table->enum('status', ['DRAFT','POSTED','VOID'])->default('DRAFT'); $table->text('note')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('posted_at')->nullable(); $table->timestamps(); $table->index(['supplier_id','payment_date','status']);
        });

        if (DB::getDriverName() === 'mysql') {
            foreach (['purchase_order_item', 'purchase_receipt_item', 'purchase_return_item'] as $table) {
                DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `{$table}_one_item_chk` CHECK ((`raw_material_id` IS NOT NULL) + (`part_id` IS NOT NULL) = 1)");
            }
        }
    }

    private function createMixedItemTable(string $tableName, string $headerColumn, string $headerTable, bool $withPending, ?string $sourceColumn = null, ?string $sourceTable = null): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($headerColumn, $headerTable, $withPending, $sourceColumn, $sourceTable) {
            $table->id(); $table->foreignId($headerColumn)->constrained($headerTable)->cascadeOnDelete();
            if ($sourceColumn) $table->foreignId($sourceColumn)->nullable()->constrained($sourceTable)->restrictOnDelete();
            $table->foreignId('raw_material_id')->nullable()->constrained('raw_materials')->restrictOnDelete(); $table->foreignId('part_id')->nullable()->constrained('parts')->restrictOnDelete(); $table->foreignId('unit_id')->constrained('units')->restrictOnDelete(); $table->decimal('conversion_to_base', 20, 6)->default(1); $table->decimal('quantity', 20, 6); $table->decimal('base_quantity', 20, 6); if ($withPending) $table->decimal('received_base_quantity', 20, 6)->default(0); $table->decimal('unit_rate', 20, 4); $table->decimal('line_total', 20, 4); $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['purchase_payment','purchase_return_item','purchase_return','purchase_receipt_item','purchase_receipt','purchase_order_item','purchase_order'] as $table) Schema::dropIfExists($table);
    }
};
