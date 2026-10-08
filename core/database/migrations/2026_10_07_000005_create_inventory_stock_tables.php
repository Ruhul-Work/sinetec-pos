<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createStockCore('raw_material', 'raw_materials');
        $this->createStockCore('part', 'parts');
        $this->createStockCore('finished_product', 'finished_products');

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id(); $table->string('transfer_no', 50)->unique(); $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete(); $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete(); $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete(); $table->dateTime('transfer_at'); $table->enum('status', ['DRAFT','IN_TRANSIT','POSTED','VOID'])->default('DRAFT'); $table->text('note')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('posted_at')->nullable(); $table->timestamps();
        });
        $this->createTransferItems('raw_material_transfer_items', 'raw_material_id', 'raw_materials');
        $this->createTransferItems('part_transfer_items', 'part_id', 'parts');
        $this->createTransferItems('finished_product_transfer_items', 'finished_product_id', 'finished_products');

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id(); $table->string('adjustment_no', 50)->unique(); $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete(); $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete(); $table->dateTime('adjustment_at'); $table->enum('adjustment_type', ['OPENING','GAIN','LOSS','DAMAGE','WASTAGE','CORRECTION']); $table->enum('status', ['DRAFT','APPROVED','POSTED','VOID'])->default('DRAFT'); $table->string('reason', 191)->nullable(); $table->text('note')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('approved_at')->nullable(); $table->timestamp('posted_at')->nullable(); $table->timestamps();
        });
        $this->createAdjustmentItems('raw_material_adjustment_items', 'raw_material_id', 'raw_materials');
        $this->createAdjustmentItems('part_adjustment_items', 'part_id', 'parts');
        $this->createAdjustmentItems('finished_product_adjustment_items', 'finished_product_id', 'finished_products');
    }

    private function createStockCore(string $domain, string $itemTable): void
    {
        $itemColumn = $domain.'_id';
        Schema::create($domain.'_stock_movements', function (Blueprint $table) use ($domain, $itemColumn, $itemTable) {
            $table->id(); $table->foreignId($itemColumn)->constrained($itemTable)->restrictOnDelete(); $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete(); $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete(); $table->dateTime('movement_at'); $table->string('movement_type', 50); $table->enum('direction', ['IN','OUT']); $table->decimal('quantity', 20, 6); $table->decimal('unit_cost', 20, 4)->nullable(); $table->string('source_type', 80); $table->unsignedBigInteger('source_id'); $table->unsignedBigInteger('source_item_id')->nullable(); $table->string('posting_key', 191)->unique(); $table->foreignId('reversal_of_id')->nullable()->constrained($domain.'_stock_movements')->restrictOnDelete(); $table->text('note')->nullable(); $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps(); $table->index([$itemColumn,'warehouse_id','movement_at'], $domain.'_stock_item_wh_date_idx'); $table->index(['source_type','source_id']);
        });
        Schema::create($domain.'_stock_balances', function (Blueprint $table) use ($domain, $itemColumn, $itemTable) {
            $table->id(); $table->foreignId($itemColumn)->constrained($itemTable)->cascadeOnDelete(); $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete(); $table->decimal('quantity', 20, 6)->default(0); $table->unsignedBigInteger('version')->default(0); $table->timestamps(); $table->unique([$itemColumn,'warehouse_id'], $domain.'_stock_balance_unique');
        });
        Schema::create($domain.'_reorder_levels', function (Blueprint $table) use ($domain, $itemColumn, $itemTable) {
            $table->id(); $table->foreignId($itemColumn)->constrained($itemTable)->cascadeOnDelete(); $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete(); $table->decimal('minimum_quantity', 20, 6)->default(0); $table->decimal('reorder_quantity', 20, 6)->default(0); $table->decimal('maximum_quantity', 20, 6)->nullable(); $table->timestamps(); $table->unique([$itemColumn,'warehouse_id'], $domain.'_reorder_level_unique');
        });
    }

    private function createTransferItems(string $tableName, string $itemColumn, string $itemTable): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($itemColumn, $itemTable) { $table->id(); $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete(); $table->foreignId($itemColumn)->constrained($itemTable)->restrictOnDelete(); $table->foreignId('unit_id')->constrained('units')->restrictOnDelete(); $table->decimal('quantity', 20, 6); $table->decimal('base_quantity', 20, 6); $table->decimal('unit_cost', 20, 4)->nullable(); $table->timestamps(); });
    }

    private function createAdjustmentItems(string $tableName, string $itemColumn, string $itemTable): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($itemColumn, $itemTable) { $table->id(); $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete(); $table->foreignId($itemColumn)->constrained($itemTable)->restrictOnDelete(); $table->foreignId('unit_id')->constrained('units')->restrictOnDelete(); $table->enum('direction', ['IN','OUT']); $table->decimal('quantity', 20, 6); $table->decimal('base_quantity', 20, 6); $table->decimal('unit_cost', 20, 4)->nullable(); $table->text('note')->nullable(); $table->timestamps(); });
    }

    public function down(): void
    {
        foreach (['finished_product_adjustment_items','part_adjustment_items','raw_material_adjustment_items','stock_adjustments','finished_product_transfer_items','part_transfer_items','raw_material_transfer_items','stock_transfers','finished_product_reorder_levels','finished_product_stock_balances','finished_product_stock_movements','part_reorder_levels','part_stock_balances','part_stock_movements','raw_material_reorder_levels','raw_material_stock_balances','raw_material_stock_movements'] as $table) Schema::dropIfExists($table);
    }
};
