<?php

namespace Tests\Feature;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockAdjustmentStatus;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Exceptions\Inventory\InvalidStockAdjustmentException;
use App\Exceptions\Inventory\StockPostingException;
use App\Models\backend\Branch;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Services\Inventory\RawMaterialStockPostingService;
use App\Services\Inventory\StockAdjustmentService;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_gain_draft_forces_in_direction_and_converts_mapped_unit(): void
    {
        [$raw, , , $warehouse, , , $box] = $this->foundation();
        DB::table('raw_material_units')->insert([
            'raw_material_id' => $raw->id, 'unit_id' => $box->id, 'conversion_to_base' => 12,
            'usage_type' => 'ALL', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $draft = app(StockAdjustmentService::class)->createDraft(
            $this->header($warehouse->id, 'GAIN'),
            [$this->line('raw_material', $raw->id, $box->id, 2, 'OUT', 5.25)],
        );

        $this->assertSame(StockAdjustmentStatus::Draft, $draft->status);
        $this->assertSame('GAIN', $draft->adjustment_type);
        $this->assertDatabaseHas('raw_material_adjustment_items', [
            'stock_adjustment_id' => $draft->id,
            'raw_material_id' => $raw->id,
            'direction' => 'IN',
            'quantity' => 2,
            'base_quantity' => 24,
            'unit_cost' => 5.25,
        ]);
    }

    public function test_correction_requires_an_explicit_valid_direction(): void
    {
        [$raw, , , $warehouse, , $piece] = $this->foundation();

        $this->expectException(InvalidStockAdjustmentException::class);
        try {
            app(StockAdjustmentService::class)->createDraft(
                $this->header($warehouse->id, 'CORRECTION'),
                [$this->line('raw_material', $raw->id, $piece->id, 2, null)],
            );
        } finally {
            $this->assertDatabaseCount('stock_adjustments', 0);
            $this->assertDatabaseCount('raw_material_adjustment_items', 0);
        }
    }

    public function test_approved_gain_and_damage_post_to_ledger_and_balance(): void
    {
        [$raw, , , $warehouse, $branch, $piece] = $this->foundation();
        $service = app(StockAdjustmentService::class);

        $gain = $service->createDraft(
            $this->header($warehouse->id, 'GAIN'),
            [$this->line('raw_material', $raw->id, $piece->id, 10, null, 3.5)],
        );
        try {
            $service->post($gain);
            $this->fail('A draft adjustment must not post directly.');
        } catch (InvalidStockAdjustmentException) {
            $this->assertDatabaseCount('raw_material_stock_movements', 0);
        }

        $gain = $service->post($service->approve($gain));
        $damage = $service->createDraft(
            $this->header($warehouse->id, 'DAMAGE'),
            [$this->line('raw_material', $raw->id, $piece->id, 3, 'IN')],
        );
        $damage = $service->post($service->approve($damage));

        $this->assertSame(StockAdjustmentStatus::Posted, $gain->status);
        $this->assertSame(StockAdjustmentStatus::Posted, $damage->status);
        $this->assertDatabaseHas('raw_material_stock_balances', [
            'raw_material_id' => $raw->id, 'warehouse_id' => $warehouse->id, 'quantity' => 7, 'version' => 2,
        ]);
        $this->assertDatabaseHas('raw_material_stock_movements', [
            'source_id' => $gain->id, 'movement_type' => 'ADJUSTMENT', 'direction' => 'IN', 'quantity' => 10,
        ]);
        $this->assertDatabaseHas('raw_material_stock_movements', [
            'source_id' => $damage->id, 'movement_type' => 'DAMAGE', 'direction' => 'OUT', 'quantity' => 3,
        ]);
        $this->assertSame($branch->id, $damage->branch_id);
    }

    public function test_insufficient_out_adjustment_rolls_back_every_line_and_keeps_approved_status(): void
    {
        [$raw, $part, , $warehouse, $branch, $piece] = $this->foundation();
        app(RawMaterialStockPostingService::class)->post(new StockPostingData(
            itemId: $raw->id,
            branchId: $branch->id,
            warehouseId: $warehouse->id,
            movementType: StockMovementType::Adjustment,
            direction: StockDirection::In,
            quantity: 5,
            sourceType: 'test',
            sourceId: 1,
            postingKey: 'test:stock-adjustment:opening',
        ));

        $service = app(StockAdjustmentService::class);
        $approved = $service->approve($service->createDraft(
            $this->header($warehouse->id, 'LOSS'),
            [
                $this->line('raw_material', $raw->id, $piece->id, 2),
                $this->line('part', $part->id, $piece->id, 1),
            ],
        ));

        try {
            $service->post($approved);
            $this->fail('Posting should fail when any OUT line has insufficient stock.');
        } catch (StockPostingException) {
            $this->assertDatabaseHas('stock_adjustments', ['id' => $approved->id, 'status' => 'APPROVED']);
            $this->assertDatabaseHas('raw_material_stock_balances', [
                'raw_material_id' => $raw->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5, 'version' => 1,
            ]);
            $this->assertDatabaseCount('part_stock_movements', 0);
            $this->assertSame(1, DB::table('raw_material_stock_movements')->count());
        }
    }

    public function test_post_retry_is_idempotent_and_posted_document_is_immutable(): void
    {
        [$raw, , , $warehouse, , $piece] = $this->foundation();
        $service = app(StockAdjustmentService::class);
        $draft = $service->createDraft(
            $this->header($warehouse->id, 'CORRECTION'),
            [$this->line('raw_material', $raw->id, $piece->id, 4, 'IN')],
        );
        $posted = $service->post($service->approve($draft));
        $retry = $service->post($posted);

        $this->assertSame(StockAdjustmentStatus::Posted, $retry->status);
        $this->assertDatabaseCount('raw_material_stock_movements', 1);
        $this->assertDatabaseHas('raw_material_stock_balances', [
            'raw_material_id' => $raw->id, 'warehouse_id' => $warehouse->id, 'quantity' => 4, 'version' => 1,
        ]);

        $this->expectException(InvalidStockAdjustmentException::class);
        $service->updateDraft($posted, $this->header($warehouse->id, 'GAIN'), [
            $this->line('raw_material', $raw->id, $piece->id, 5),
        ]);
    }

    public function test_stock_adjustment_routes_have_an_independent_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);
        $permissionId = DB::table('permissions')->where('key', 'inventory.stock_adjustment')->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(12, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
        $this->assertDatabaseHas('permission_routes', ['permission_id' => $permissionId, 'route_name' => 'inventory.stock-adjustments.index']);
        $this->assertDatabaseHas('permission_routes', ['permission_id' => $permissionId, 'route_name' => 'inventory.stock-adjustments.post']);
    }

    /** @return array<string, mixed> */
    private function header(int $warehouseId, string $type): array
    {
        return [
            'warehouse_id' => $warehouseId,
            'adjustment_type' => $type,
            'adjustment_at' => '2026-10-29 10:00:00',
            'reason' => 'Verified stock difference',
            'note' => 'Checked by store operator',
        ];
    }

    /** @return array<string, mixed> */
    private function line(string $domain, int $itemId, int $unitId, int|float|string $quantity, ?string $direction = null, int|float|string|null $unitCost = null): array
    {
        return [
            'domain' => $domain, 'item_id' => $itemId, 'unit_id' => $unitId,
            'direction' => $direction, 'quantity' => $quantity, 'unit_cost' => $unitCost,
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
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Main Warehouse', 'code' => 'WH-001', 'type' => 'store', 'is_default' => true, 'is_active' => true]);
        $raw = RawMaterial::create(['material_code' => 'RM-001', 'name' => 'Copper Wire', 'raw_material_category_id' => $rawCategory->id, 'base_unit_id' => $piece->id, 'material_kind' => 'MATERIAL', 'is_active' => true]);
        $part = Part::create(['part_code' => 'PT-001', 'name' => 'Transformer', 'part_category_id' => $partCategory->id, 'base_unit_id' => $piece->id, 'procurement_mode' => 'BOTH', 'is_active' => true]);
        $product = FinishedProduct::create(['product_code' => 'FG-001', 'name' => 'Voltage Stabilizer', 'finished_product_category_id' => $finishedCategory->id, 'base_unit_id' => $piece->id, 'is_active' => true]);

        return [$raw, $part, $product, $warehouse, $branch, $piece, $box];
    }
}
