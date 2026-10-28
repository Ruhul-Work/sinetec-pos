<?php

namespace Tests\Feature;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockAdjustmentStatus;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Exceptions\Inventory\InvalidOpeningStockException;
use App\Models\backend\Branch;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Services\Inventory\OpeningStockService;
use App\Services\Inventory\RawMaterialStockPostingService;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OpeningStockServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_stores_each_domain_in_its_own_table_and_converts_to_base_quantity(): void
    {
        [$raw, $part, $product, $warehouse, , $piece, $box] = $this->foundation();
        DB::table('raw_material_units')->insert([
            'raw_material_id' => $raw->id,
            'unit_id' => $box->id,
            'conversion_to_base' => 12,
            'usage_type' => 'ALL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $draft = app(OpeningStockService::class)->createDraft(
            $this->header($warehouse->id),
            [
                $this->line('raw_material', $raw->id, $box->id, 2, 5.25),
                $this->line('part', $part->id, $piece->id, 3),
                $this->line('finished_product', $product->id, $piece->id, 4),
            ],
        );

        $this->assertSame(StockAdjustmentStatus::Draft, $draft->status);
        $this->assertSame('OPENING', $draft->adjustment_type);
        $this->assertSame($warehouse->branch_id, $draft->branch_id);
        $this->assertDatabaseHas('raw_material_adjustment_items', [
            'stock_adjustment_id' => $draft->id,
            'raw_material_id' => $raw->id,
            'unit_id' => $box->id,
            'quantity' => 2,
            'base_quantity' => 24,
            'unit_cost' => 5.25,
            'direction' => 'IN',
        ]);
        $this->assertDatabaseHas('part_adjustment_items', ['stock_adjustment_id' => $draft->id, 'part_id' => $part->id]);
        $this->assertDatabaseHas('finished_product_adjustment_items', ['stock_adjustment_id' => $draft->id, 'finished_product_id' => $product->id]);
    }

    public function test_duplicate_item_in_one_document_is_rejected_atomically(): void
    {
        [$raw, , , $warehouse, , $piece] = $this->foundation();

        try {
            app(OpeningStockService::class)->createDraft(
                $this->header($warehouse->id),
                [
                    $this->line('raw_material', $raw->id, $piece->id, 2),
                    $this->line('raw_material', $raw->id, $piece->id, 3),
                ],
            );
            $this->fail('Duplicate items should be rejected.');
        } catch (InvalidOpeningStockException) {
            $this->assertDatabaseCount('stock_adjustments', 0);
            $this->assertDatabaseCount('raw_material_adjustment_items', 0);
        }
    }

    public function test_only_an_approved_document_posts_all_domains_to_their_ledgers(): void
    {
        [$raw, $part, $product, $warehouse, , $piece] = $this->foundation();
        $service = app(OpeningStockService::class);
        $draft = $service->createDraft($this->header($warehouse->id), [
            $this->line('raw_material', $raw->id, $piece->id, 10, 3.5),
            $this->line('part', $part->id, $piece->id, 5, 8),
            $this->line('finished_product', $product->id, $piece->id, 2, 20),
        ]);

        try {
            $service->post($draft);
            $this->fail('A draft must not be posted directly.');
        } catch (InvalidOpeningStockException) {
            $this->assertDatabaseCount('raw_material_stock_movements', 0);
        }

        $approved = $service->approve($draft);
        $posted = $service->post($approved);

        $this->assertSame(StockAdjustmentStatus::Approved, $approved->status);
        $this->assertSame(StockAdjustmentStatus::Posted, $posted->status);
        $this->assertDatabaseHas('raw_material_stock_balances', ['raw_material_id' => $raw->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);
        $this->assertDatabaseHas('part_stock_balances', ['part_id' => $part->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5]);
        $this->assertDatabaseHas('finished_product_stock_balances', ['finished_product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 2]);
        $this->assertDatabaseHas('raw_material_stock_movements', ['source_type' => 'stock_adjustment', 'source_id' => $draft->id, 'movement_type' => 'OPENING', 'unit_cost' => 3.5]);
    }

    public function test_post_retry_is_idempotent(): void
    {
        [$raw, , , $warehouse, , $piece] = $this->foundation();
        $service = app(OpeningStockService::class);
        $draft = $service->createDraft($this->header($warehouse->id), [
            $this->line('raw_material', $raw->id, $piece->id, 7),
        ]);

        $posted = $service->post($service->approve($draft));
        $retry = $service->post($posted);

        $this->assertSame(StockAdjustmentStatus::Posted, $retry->status);
        $this->assertDatabaseCount('raw_material_stock_movements', 1);
        $this->assertDatabaseHas('raw_material_stock_balances', [
            'raw_material_id' => $raw->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 7,
            'version' => 1,
        ]);
    }

    public function test_opening_is_rejected_when_the_item_already_has_a_movement_in_the_warehouse(): void
    {
        [$raw, , , $warehouse, $branch, $piece] = $this->foundation();
        app(RawMaterialStockPostingService::class)->post(new StockPostingData(
            itemId: $raw->id,
            branchId: $branch->id,
            warehouseId: $warehouse->id,
            movementType: StockMovementType::PurchaseReceipt,
            direction: StockDirection::In,
            quantity: 1,
            sourceType: 'test_purchase',
            sourceId: 1,
            postingKey: 'purchase:existing:1',
        ));

        $service = app(OpeningStockService::class);
        $draft = $service->createDraft($this->header($warehouse->id), [
            $this->line('raw_material', $raw->id, $piece->id, 7),
        ]);

        $this->expectException(InvalidOpeningStockException::class);
        $service->approve($draft);
    }

    public function test_an_approved_document_cannot_be_edited_or_deleted(): void
    {
        [$raw, , , $warehouse, , $piece] = $this->foundation();
        $service = app(OpeningStockService::class);
        $draft = $service->createDraft($this->header($warehouse->id), [
            $this->line('raw_material', $raw->id, $piece->id, 7),
        ]);
        $approved = $service->approve($draft);

        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $service->updateDraft($approved, $this->header($warehouse->id), [
                        $this->line('raw_material', $raw->id, $piece->id, 8),
                    ]);
                } else {
                    $service->deleteDraft($approved);
                }
                $this->fail("Approved opening stock should not allow {$operation}.");
            } catch (InvalidOpeningStockException) {
                $this->assertDatabaseHas('stock_adjustments', ['id' => $approved->id, 'status' => 'APPROVED']);
            }
        }
    }

    public function test_opening_stock_routes_have_an_independent_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);

        $permissionId = DB::table('permissions')->where('key', 'inventory.opening_stock')->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(12, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
        $this->assertDatabaseHas('permission_routes', [
            'permission_id' => $permissionId,
            'route_name' => 'inventory.opening-stocks.index',
        ]);
        $this->assertDatabaseHas('permission_routes', [
            'permission_id' => $permissionId,
            'route_name' => 'inventory.opening-stocks.post',
        ]);
    }

    /** @return array<string, mixed> */
    private function header(int $warehouseId): array
    {
        return [
            'warehouse_id' => $warehouseId,
            'adjustment_at' => '2026-10-25 10:00:00',
            'reason' => 'Initial stock count',
            'note' => 'Verified before entry',
        ];
    }

    /** @return array<string, mixed> */
    private function line(string $domain, int $itemId, int $unitId, int|float|string $quantity, int|float|string|null $unitCost = null): array
    {
        return [
            'domain' => $domain,
            'item_id' => $itemId,
            'unit_id' => $unitId,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
        ];
    }

    /** @return array{RawMaterial, Part, FinishedProduct, Warehouse, Branch, Unit, Unit} */
    private function foundation(): array
    {
        $piece = Unit::create(['name' => 'Piece', 'code' => 'PCS', 'precision' => 0, 'is_active' => true]);
        $box = Unit::create(['name' => 'Box', 'code' => 'BOX', 'precision' => 0, 'is_active' => true]);
        $rawCategory = RawMaterialCategory::create(['code' => 'RM', 'name' => 'Raw Materials', 'is_active' => true]);
        $partCategory = PartCategory::create(['code' => 'PART', 'name' => 'Parts', 'is_active' => true]);
        $finishedCategory = FinishedProductCategory::create(['code' => 'FG', 'name' => 'Finished Products', 'is_active' => true]);
        $branch = Branch::create(['name' => 'Main Branch', 'code' => 'BR-001', 'is_active' => true]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Main Warehouse',
            'code' => 'WH-001',
            'type' => 'store',
            'is_default' => true,
            'is_active' => true,
        ]);
        $raw = RawMaterial::create([
            'material_code' => 'RM-001', 'name' => 'Copper Wire',
            'raw_material_category_id' => $rawCategory->id, 'base_unit_id' => $piece->id,
            'material_kind' => 'MATERIAL', 'is_active' => true,
        ]);
        $part = Part::create([
            'part_code' => 'PT-001', 'name' => 'Transformer',
            'part_category_id' => $partCategory->id, 'base_unit_id' => $piece->id,
            'procurement_mode' => 'BOTH', 'is_active' => true,
        ]);
        $product = FinishedProduct::create([
            'product_code' => 'FG-001', 'name' => 'Voltage Stabilizer',
            'finished_product_category_id' => $finishedCategory->id, 'base_unit_id' => $piece->id,
            'is_active' => true,
        ]);

        return [$raw, $part, $product, $warehouse, $branch, $piece, $box];
    }
}
