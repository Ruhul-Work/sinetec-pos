<?php

namespace Tests\Feature;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Inventory\StockTransferStatus;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InvalidStockTransferException;
use App\Models\backend\Branch;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Services\Inventory\FinishedProductStockPostingService;
use App\Services\Inventory\PartStockPostingService;
use App\Services\Inventory\RawMaterialStockPostingService;
use App\Services\Inventory\StockTransferService;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_stores_each_domain_separately_and_converts_alternative_units(): void
    {
        [$raw, $part, $product, $source, $destination, $piece, $box] = $this->foundation();
        DB::table('raw_material_units')->insert([
            'raw_material_id' => $raw->id, 'unit_id' => $box->id, 'conversion_to_base' => 12,
            'usage_type' => 'ALL', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $draft = app(StockTransferService::class)->createDraft($this->header($source, $destination), [
            $this->line('raw_material', $raw->id, $box->id, 2),
            $this->line('part', $part->id, $piece->id, 3),
            $this->line('finished_product', $product->id, $piece->id, 4),
        ]);

        $this->assertSame(StockTransferStatus::Draft, $draft->status);
        $this->assertSame($source->branch_id, $draft->branch_id);
        $this->assertDatabaseHas('raw_material_transfer_items', [
            'stock_transfer_id' => $draft->id, 'raw_material_id' => $raw->id,
            'quantity' => 2, 'base_quantity' => 24,
        ]);
        $this->assertDatabaseHas('part_transfer_items', ['stock_transfer_id' => $draft->id, 'part_id' => $part->id]);
        $this->assertDatabaseHas('finished_product_transfer_items', ['stock_transfer_id' => $draft->id, 'finished_product_id' => $product->id]);
    }

    public function test_source_and_destination_must_be_different(): void
    {
        [$raw, , , $source, , $piece] = $this->foundation();

        $this->expectException(InvalidStockTransferException::class);
        app(StockTransferService::class)->createDraft($this->header($source, $source), [
            $this->line('raw_material', $raw->id, $piece->id, 1),
        ]);
    }

    public function test_post_moves_all_domains_between_warehouses_and_supports_cross_branch_transfer(): void
    {
        [$raw, $part, $product, $source, $destination, $piece] = $this->foundation();
        $this->opening($raw->id, $part->id, $product->id, $source, 20);
        $service = app(StockTransferService::class);
        $draft = $service->createDraft($this->header($source, $destination), [
            $this->line('raw_material', $raw->id, $piece->id, 5),
            $this->line('part', $part->id, $piece->id, 4),
            $this->line('finished_product', $product->id, $piece->id, 3),
        ]);

        $posted = $service->post($draft);

        $this->assertSame(StockTransferStatus::Posted, $posted->status);
        $this->assertDatabaseHas('raw_material_stock_balances', ['raw_material_id' => $raw->id, 'warehouse_id' => $source->id, 'quantity' => 15]);
        $this->assertDatabaseHas('raw_material_stock_balances', ['raw_material_id' => $raw->id, 'warehouse_id' => $destination->id, 'quantity' => 5]);
        $this->assertDatabaseHas('part_stock_balances', ['part_id' => $part->id, 'warehouse_id' => $source->id, 'quantity' => 16]);
        $this->assertDatabaseHas('part_stock_balances', ['part_id' => $part->id, 'warehouse_id' => $destination->id, 'quantity' => 4]);
        $this->assertDatabaseHas('finished_product_stock_balances', ['finished_product_id' => $product->id, 'warehouse_id' => $source->id, 'quantity' => 17]);
        $this->assertDatabaseHas('finished_product_stock_balances', ['finished_product_id' => $product->id, 'warehouse_id' => $destination->id, 'quantity' => 3]);
        $this->assertDatabaseHas('raw_material_stock_movements', ['source_type' => 'stock_transfer', 'source_id' => $draft->id, 'warehouse_id' => $source->id, 'movement_type' => 'TRANSFER_OUT']);
        $this->assertDatabaseHas('raw_material_stock_movements', ['source_type' => 'stock_transfer', 'source_id' => $draft->id, 'warehouse_id' => $destination->id, 'movement_type' => 'TRANSFER_IN']);
    }

    public function test_insufficient_stock_rolls_back_the_complete_transfer(): void
    {
        [$raw, $part, , $source, $destination, $piece] = $this->foundation();
        app(RawMaterialStockPostingService::class)->post($this->posting($raw->id, $source, 10, 'opening:raw'));
        app(PartStockPostingService::class)->post($this->posting($part->id, $source, 1, 'opening:part'));
        $service = app(StockTransferService::class);
        $draft = $service->createDraft($this->header($source, $destination), [
            $this->line('raw_material', $raw->id, $piece->id, 5),
            $this->line('part', $part->id, $piece->id, 2),
        ]);

        try {
            $service->post($draft);
            $this->fail('Insufficient stock should reject the complete transfer.');
        } catch (InsufficientStockException) {
            $this->assertDatabaseHas('stock_transfers', ['id' => $draft->id, 'status' => 'DRAFT']);
            $this->assertDatabaseHas('raw_material_stock_balances', ['raw_material_id' => $raw->id, 'warehouse_id' => $source->id, 'quantity' => 10]);
            $this->assertDatabaseMissing('raw_material_stock_balances', ['raw_material_id' => $raw->id, 'warehouse_id' => $destination->id]);
            $this->assertDatabaseCount('raw_material_stock_movements', 1);
        }
    }

    public function test_post_retry_is_idempotent_and_posted_transfer_is_immutable(): void
    {
        [$raw, , , $source, $destination, $piece] = $this->foundation();
        app(RawMaterialStockPostingService::class)->post($this->posting($raw->id, $source, 10, 'opening:raw'));
        $service = app(StockTransferService::class);
        $draft = $service->createDraft($this->header($source, $destination), [
            $this->line('raw_material', $raw->id, $piece->id, 4),
        ]);
        $posted = $service->post($draft);
        $retry = $service->post($posted);

        $this->assertSame(StockTransferStatus::Posted, $retry->status);
        $this->assertDatabaseCount('raw_material_stock_movements', 3);

        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $service->updateDraft($posted, $this->header($source, $destination), [
                        $this->line('raw_material', $raw->id, $piece->id, 1),
                    ]);
                } else {
                    $service->deleteDraft($posted);
                }
                $this->fail("Posted transfer should not allow {$operation}.");
            } catch (InvalidStockTransferException) {
                $this->assertDatabaseHas('stock_transfers', ['id' => $posted->id, 'status' => 'POSTED']);
            }
        }
    }

    public function test_stock_transfer_routes_have_an_independent_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);
        $permissionId = DB::table('permissions')->where('key', 'inventory.stock_transfer')->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(11, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
        $this->assertDatabaseHas('permission_routes', ['permission_id' => $permissionId, 'route_name' => 'inventory.stock-transfers.index']);
        $this->assertDatabaseHas('permission_routes', ['permission_id' => $permissionId, 'route_name' => 'inventory.stock-transfers.post']);
    }

    /** @return array<string, mixed> */
    private function header(Warehouse $source, Warehouse $destination): array
    {
        return [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'transfer_at' => '2026-10-28 10:00:00',
            'note' => 'Branch replenishment',
        ];
    }

    /** @return array<string, mixed> */
    private function line(string $domain, int $itemId, int $unitId, int|float|string $quantity): array
    {
        return ['domain' => $domain, 'item_id' => $itemId, 'unit_id' => $unitId, 'quantity' => $quantity];
    }

    private function posting(int $itemId, Warehouse $warehouse, int|float|string $quantity, string $key): StockPostingData
    {
        return new StockPostingData(
            itemId: $itemId,
            branchId: $warehouse->branch_id,
            warehouseId: $warehouse->id,
            movementType: StockMovementType::Opening,
            direction: StockDirection::In,
            quantity: $quantity,
            sourceType: 'test_opening',
            sourceId: 1,
            postingKey: $key,
        );
    }

    private function opening(int $rawId, int $partId, int $productId, Warehouse $warehouse, int $quantity): void
    {
        app(RawMaterialStockPostingService::class)->post($this->posting($rawId, $warehouse, $quantity, 'opening:raw'));
        app(PartStockPostingService::class)->post($this->posting($partId, $warehouse, $quantity, 'opening:part'));
        app(FinishedProductStockPostingService::class)->post($this->posting($productId, $warehouse, $quantity, 'opening:product'));
    }

    /** @return array{RawMaterial, Part, FinishedProduct, Warehouse, Warehouse, Unit, Unit} */
    private function foundation(): array
    {
        $piece = Unit::create(['name' => 'Piece', 'code' => 'PCS', 'precision' => 0, 'is_active' => true]);
        $box = Unit::create(['name' => 'Box', 'code' => 'BOX', 'precision' => 0, 'is_active' => true]);
        $rawCategory = RawMaterialCategory::create(['code' => 'RM', 'name' => 'Raw Materials', 'is_active' => true]);
        $partCategory = PartCategory::create(['code' => 'PART', 'name' => 'Parts', 'is_active' => true]);
        $finishedCategory = FinishedProductCategory::create(['code' => 'FG', 'name' => 'Finished Products', 'is_active' => true]);
        $sourceBranch = Branch::create(['name' => 'Factory', 'code' => 'BR-001', 'is_active' => true]);
        $destinationBranch = Branch::create(['name' => 'Showroom', 'code' => 'BR-002', 'is_active' => true]);
        $source = Warehouse::create(['branch_id' => $sourceBranch->id, 'name' => 'Factory Store', 'code' => 'WH-001', 'type' => 'store', 'is_default' => true, 'is_active' => true]);
        $destination = Warehouse::create(['branch_id' => $destinationBranch->id, 'name' => 'Showroom Store', 'code' => 'WH-002', 'type' => 'showroom', 'is_default' => true, 'is_active' => true]);
        $raw = RawMaterial::create(['material_code' => 'RM-001', 'name' => 'Copper Wire', 'raw_material_category_id' => $rawCategory->id, 'base_unit_id' => $piece->id, 'material_kind' => 'MATERIAL', 'is_active' => true]);
        $part = Part::create(['part_code' => 'PT-001', 'name' => 'Transformer', 'part_category_id' => $partCategory->id, 'base_unit_id' => $piece->id, 'procurement_mode' => 'BOTH', 'is_active' => true]);
        $product = FinishedProduct::create(['product_code' => 'FG-001', 'name' => 'Voltage Stabilizer', 'finished_product_category_id' => $finishedCategory->id, 'base_unit_id' => $piece->id, 'is_active' => true]);

        return [$raw, $part, $product, $source, $destination, $piece, $box];
    }
}
