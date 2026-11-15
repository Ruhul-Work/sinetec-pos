<?php

namespace Tests\Feature;

use App\Enums\Production\ProductAssemblyStatus;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\backend\Branch;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Models\Inventory\PartStockBalance;
use App\Models\Inventory\RawMaterialStockBalance;
use App\Models\Production\ProductBom;
use App\Services\Production\ProductAssemblyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAssemblyTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_consumes_mixed_components_and_receives_finished_product(): void
    {
        $setup = $this->setupAssembly('10', '8');
        $service = app(ProductAssemblyService::class);
        $batch = $service->createDraft(
            $this->header($setup, '2', '2'),
            $this->consumptions($setup, '2', '4'),
            [],
            null,
        );

        $completed = $service->complete($batch, null);

        $this->assertSame(ProductAssemblyStatus::Completed, $completed->status);
        $this->assertDatabaseHas('raw_material_stock_balances', [
            'raw_material_id' => $setup['raw_material']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => 8,
        ]);
        $this->assertDatabaseHas('part_stock_balances', [
            'part_id' => $setup['part']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => 4,
        ]);
        $this->assertDatabaseHas('finished_product_stock_balances', [
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('raw_material_stock_movements', [
            'source_type' => 'product_assembly_batch',
            'movement_type' => 'ASSEMBLY_CONSUMPTION',
            'direction' => 'OUT',
        ]);
        $this->assertDatabaseHas('part_stock_movements', [
            'source_type' => 'product_assembly_batch',
            'movement_type' => 'ASSEMBLY_CONSUMPTION',
            'direction' => 'OUT',
        ]);
        $this->assertDatabaseHas('finished_product_stock_movements', [
            'source_type' => 'product_assembly_batch',
            'movement_type' => 'ASSEMBLY_OUTPUT',
            'direction' => 'IN',
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'product_assembly_batch',
            'source_id' => $batch->id,
        ]);
    }

    public function test_part_shortage_rolls_back_raw_material_consumption_and_output(): void
    {
        $setup = $this->setupAssembly('10', '1');
        $service = app(ProductAssemblyService::class);
        $batch = $service->createDraft(
            $this->header($setup, '2', '2'),
            $this->consumptions($setup, '2', '4'),
            [],
            null,
        );

        try {
            $service->complete($batch, null);
            $this->fail('Completion should fail when Part stock is insufficient.');
        } catch (InsufficientStockException) {
            // Expected: both component postings and output are one atomic transaction.
        }

        $this->assertSame(ProductAssemblyStatus::Draft, $batch->fresh()->status);
        $this->assertDatabaseHas('raw_material_stock_balances', [
            'raw_material_id' => $setup['raw_material']->id,
            'quantity' => 10,
        ]);
        $this->assertDatabaseMissing('raw_material_stock_movements', [
            'source_type' => 'product_assembly_batch',
            'source_id' => $batch->id,
        ]);
        $this->assertDatabaseMissing('finished_product_stock_balances', [
            'finished_product_id' => $setup['product']->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function setupAssembly(string $rawStock, string $partStock): array
    {
        $unit = Unit::query()->create([
            'name' => 'Piece',
            'code' => 'PCS',
            'precision' => 0,
            'is_active' => true,
        ]);
        $rawCategory = RawMaterialCategory::query()->create([
            'code' => 'RAW',
            'name' => 'Raw Materials',
            'is_active' => true,
        ]);
        $partCategory = PartCategory::query()->create([
            'code' => 'PART',
            'name' => 'Parts',
            'is_active' => true,
        ]);
        $productCategory = FinishedProductCategory::query()->create([
            'code' => 'PRODUCT',
            'name' => 'Finished Products',
            'is_active' => true,
        ]);
        $rawMaterial = RawMaterial::query()->create([
            'material_code' => 'RM-001',
            'name' => 'Wire',
            'raw_material_category_id' => $rawCategory->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'MATERIAL',
            'is_active' => true,
        ]);
        $part = Part::query()->create([
            'part_code' => 'PT-001',
            'name' => 'Board',
            'part_category_id' => $partCategory->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => 'MAKE',
            'is_active' => true,
        ]);
        $product = FinishedProduct::query()->create([
            'product_code' => 'FG-001',
            'name' => 'Controller',
            'finished_product_category_id' => $productCategory->id,
            'base_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
        $warehouse = Warehouse::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Production Store',
            'code' => 'PROD',
            'type' => 'production',
            'is_default' => true,
            'is_active' => true,
        ]);
        $bom = ProductBom::query()->create([
            'finished_product_id' => $product->id,
            'version' => 'V1',
            'output_quantity' => '1',
            'status' => 'ACTIVE',
            'effective_from' => today(),
        ]);
        $bom->items()->createMany([
            [
                'raw_material_id' => $rawMaterial->id,
                'unit_id' => $unit->id,
                'required_quantity' => '1',
                'base_quantity' => '1',
                'wastage_percent' => '0',
            ],
            [
                'part_id' => $part->id,
                'unit_id' => $unit->id,
                'required_quantity' => '2',
                'base_quantity' => '2',
                'wastage_percent' => '0',
            ],
        ]);
        RawMaterialStockBalance::query()->create([
            'raw_material_id' => $rawMaterial->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $rawStock,
            'version' => 1,
        ]);
        PartStockBalance::query()->create([
            'part_id' => $part->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $partStock,
            'version' => 1,
        ]);

        return [
            'unit' => $unit,
            'raw_material' => $rawMaterial,
            'part' => $part,
            'product' => $product,
            'branch' => $branch,
            'warehouse' => $warehouse,
            'bom' => $bom,
        ];
    }

    private function header(array $setup, string $planned, string $good): array
    {
        return [
            'product_bom_id' => $setup['bom']->id,
            'input_warehouse_id' => $setup['warehouse']->id,
            'output_warehouse_id' => $setup['warehouse']->id,
            'assembly_date' => today()->toDateString(),
            'planned_quantity' => $planned,
            'good_quantity' => $good,
            'reject_quantity' => '0',
            'qc_status' => 'PASSED',
        ];
    }

    private function consumptions(array $setup, string $rawQuantity, string $partQuantity): array
    {
        return [
            [
                'component_type' => 'RAW_MATERIAL',
                'raw_material_id' => $setup['raw_material']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => $rawQuantity,
            ],
            [
                'component_type' => 'PART',
                'part_id' => $setup['part']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => $partQuantity,
            ],
        ];
    }
}
