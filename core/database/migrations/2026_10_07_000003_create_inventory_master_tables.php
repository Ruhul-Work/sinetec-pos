<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'raw_material_categories' => 'raw_material_categories',
            'part_categories' => 'part_categories',
            'finished_product_categories' => 'finished_product_categories',
        ] as $tableName => $parentTable) {
            Schema::create($tableName, function (Blueprint $table) use ($parentTable) {
                $table->id();
                $table->foreignId('parent_id')->nullable()->constrained($parentTable)->restrictOnDelete();
                $table->string('code', 50)->unique();
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::create('raw_materials', function (Blueprint $table) {
            $table->id();
            $table->string('material_code', 80)->unique();
            $table->string('barcode', 100)->nullable()->unique();
            $table->string('name', 200);
            $table->foreignId('raw_material_category_id')->constrained('raw_material_categories')->restrictOnDelete();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->enum('material_kind', ['MATERIAL', 'PACKAGING', 'CONSUMABLE'])->default('MATERIAL');
            $table->text('specification')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['raw_material_category_id', 'is_active'], 'raw_material_category_active_idx');
            $table->index(['material_kind', 'is_active'], 'raw_material_kind_active_idx');
        });
        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->string('part_code', 80)->unique();
            $table->string('barcode', 100)->nullable()->unique();
            $table->string('name', 200);
            $table->foreignId('part_category_id')->constrained('part_categories')->restrictOnDelete();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->enum('procurement_mode', ['MAKE', 'BUY', 'BOTH']);
            $table->text('specification')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['part_category_id', 'is_active']);
            $table->index(['procurement_mode', 'is_active']);
        });
        Schema::create('finished_products', function (Blueprint $table) {
            $table->id();
            $table->string('product_code', 80)->unique();
            $table->string('barcode', 100)->nullable()->unique();
            $table->string('name', 200);
            $table->foreignId('finished_product_category_id')->constrained('finished_product_categories')->restrictOnDelete();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->decimal('retail_price', 20, 4)->nullable();
            $table->decimal('wholesale_price', 20, 4)->nullable();
            $table->text('specification')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['finished_product_category_id', 'is_active'], 'finished_product_category_active_idx');
            $table->index('is_active');
        });

        $this->createUnitMap('raw_material_units', 'raw_material_id', 'raw_materials');
        $this->createUnitMap('part_units', 'part_id', 'parts');
        $this->createUnitMap('finished_product_units', 'finished_product_id', 'finished_products');
        $this->createSupplierMap('raw_material_suppliers', 'raw_material_id', 'raw_materials');
        $this->createSupplierMap('part_suppliers', 'part_id', 'parts');
    }

    private function createUnitMap(string $tableName, string $itemColumn, string $itemTable): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($itemColumn, $itemTable) {
            $table->id();
            $table->foreignId($itemColumn)->constrained($itemTable)->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_to_base', 20, 6);
            $table->enum('usage_type', ['PURCHASE', 'PRODUCTION', 'SALE', 'ALL'])->default('ALL');
            $table->timestamps();
            $table->unique([$itemColumn, 'unit_id']);
        });
    }

    private function createSupplierMap(string $tableName, string $itemColumn, string $itemTable): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($itemColumn, $itemTable) {
            $table->id();
            $table->foreignId($itemColumn)->constrained($itemTable)->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('supplier_code', 80)->nullable();
            $table->unsignedInteger('lead_days')->default(0);
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();
            $table->unique([$itemColumn, 'supplier_id']);
        });
    }

    public function down(): void
    {
        foreach (['part_suppliers','raw_material_suppliers','finished_product_units','part_units','raw_material_units','finished_products','parts','raw_materials','finished_product_categories','part_categories','raw_material_categories'] as $table) Schema::dropIfExists($table);
    }
};
