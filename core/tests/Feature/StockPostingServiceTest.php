<?php

namespace Tests\Feature;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Exceptions\Inventory\DuplicateStockPostingException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InvalidStockPostingException;
use App\Exceptions\Inventory\StockMovementAlreadyReversedException;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_domain_posts_to_its_own_ledger_and_balance(): void
    {
        [$rawMaterial, $part, $finishedProduct, $warehouse, $branch] = $this->foundation();

        $postings = [
            [app(RawMaterialStockPostingService::class), $rawMaterial->id, 'raw_material_stock_movements', 'raw_material_stock_balances', 'raw_material_id', StockMovementType::PurchaseReceipt],
            [app(PartStockPostingService::class), $part->id, 'part_stock_movements', 'part_stock_balances', 'part_id', StockMovementType::PurchaseReceipt],
            [app(FinishedProductStockPostingService::class), $finishedProduct->id, 'finished_product_stock_movements', 'finished_product_stock_balances', 'finished_product_id', StockMovementType::AssemblyOutput],
        ];

        foreach ($postings as $index => [$service, $itemId, $movementTable, $balanceTable, $itemColumn, $movementType]) {
            $result = $service->post($this->posting(
                itemId: $itemId,
                branchId: $branch->id,
                warehouseId: $warehouse->id,
                movementType: $movementType,
                direction: StockDirection::In,
                quantity: '10.500000',
                postingKey: "foundation:{$index}",
            ));

            $this->assertFalse($result->wasAlreadyPosted);
            $this->assertSame('10.500000', $result->balance->quantity);
            $this->assertSame(1, $result->balance->version);
            $this->assertDatabaseHas($movementTable, [
                $itemColumn => $itemId,
                'posting_key' => "foundation:{$index}",
                'direction' => 'IN',
                'quantity' => 10.5,
            ]);
            $this->assertDatabaseHas($balanceTable, [
                $itemColumn => $itemId,
                'warehouse_id' => $warehouse->id,
                'quantity' => 10.5,
                'version' => 1,
            ]);
        }
    }

    public function test_in_and_out_postings_update_one_balance_and_increment_its_version(): void
    {
        [$rawMaterial, , , $warehouse, $branch] = $this->foundation();
        $service = app(RawMaterialStockPostingService::class);

        $service->post($this->posting(
            $rawMaterial->id,
            $branch->id,
            $warehouse->id,
            StockMovementType::Opening,
            StockDirection::In,
            12,
            'opening:raw:1',
        ));

        $result = $service->post($this->posting(
            $rawMaterial->id,
            $branch->id,
            $warehouse->id,
            StockMovementType::Adjustment,
            StockDirection::Out,
            2.25,
            'adjustment:raw:1',
        ));

        $this->assertSame('9.750000', $result->balance->quantity);
        $this->assertSame(2, $result->balance->version);
        $this->assertDatabaseCount('raw_material_stock_movements', 2);
    }

    public function test_a_retry_with_the_same_posting_key_is_idempotent(): void
    {
        [$rawMaterial, , , $warehouse, $branch] = $this->foundation();
        $service = app(RawMaterialStockPostingService::class);
        $posting = $this->posting(
            $rawMaterial->id,
            $branch->id,
            $warehouse->id,
            StockMovementType::Opening,
            StockDirection::In,
            5,
            'opening:raw:retry',
        );

        $first = $service->post($posting);
        $retry = $service->post($posting);

        $this->assertFalse($first->wasAlreadyPosted);
        $this->assertTrue($retry->wasAlreadyPosted);
        $this->assertSame($first->movement->id, $retry->movement->id);
        $this->assertSame('5.000000', $retry->balance->quantity);
        $this->assertSame(1, $retry->balance->version);
        $this->assertDatabaseCount('raw_material_stock_movements', 1);
    }

    public function test_a_posting_key_cannot_be_reused_with_different_data(): void
    {
        [$rawMaterial, , , $warehouse, $branch] = $this->foundation();
        $service = app(RawMaterialStockPostingService::class);

        $service->post($this->posting(
            $rawMaterial->id,
            $branch->id,
            $warehouse->id,
            StockMovementType::Opening,
            StockDirection::In,
            5,
            'opening:raw:conflict',
        ));

        try {
            $service->post($this->posting(
                $rawMaterial->id,
                $branch->id,
                $warehouse->id,
                StockMovementType::Opening,
                StockDirection::In,
                6,
                'opening:raw:conflict',
            ));
            $this->fail('A conflicting posting key should be rejected.');
        } catch (DuplicateStockPostingException) {
            $this->assertDatabaseHas('raw_material_stock_balances', [
                'raw_material_id' => $rawMaterial->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 5,
                'version' => 1,
            ]);
        }
    }

    public function test_stock_cannot_become_negative_and_failed_posting_is_rolled_back(): void
    {
        [$rawMaterial, , , $warehouse, $branch] = $this->foundation();
        $service = app(RawMaterialStockPostingService::class);

        try {
            $service->post($this->posting(
                $rawMaterial->id,
                $branch->id,
                $warehouse->id,
                StockMovementType::Adjustment,
                StockDirection::Out,
                1,
                'adjustment:negative',
            ));
            $this->fail('Negative stock should be rejected.');
        } catch (InsufficientStockException) {
            $this->assertDatabaseCount('raw_material_stock_movements', 0);
            $this->assertDatabaseCount('raw_material_stock_balances', 0);
        }
    }

    public function test_warehouse_must_belong_to_the_posting_branch(): void
    {
        [$rawMaterial, , , $warehouse] = $this->foundation();
        $otherBranch = Branch::create(['name' => 'Other', 'code' => 'BR-002', 'is_active' => true]);

        $this->expectException(InvalidStockPostingException::class);

        app(RawMaterialStockPostingService::class)->post($this->posting(
            $rawMaterial->id,
            $otherBranch->id,
            $warehouse->id,
            StockMovementType::Opening,
            StockDirection::In,
            1,
            'opening:wrong-branch',
        ));
    }

    public function test_domain_and_direction_rules_are_enforced(): void
    {
        [, $part, , $warehouse, $branch] = $this->foundation();
        $service = app(PartStockPostingService::class);

        foreach ([
            [StockMovementType::Sale, StockDirection::Out, 'part:sale'],
            [StockMovementType::PurchaseReceipt, StockDirection::Out, 'part:wrong-direction'],
        ] as [$movementType, $direction, $postingKey]) {
            try {
                $service->post($this->posting(
                    $part->id,
                    $branch->id,
                    $warehouse->id,
                    $movementType,
                    $direction,
                    1,
                    $postingKey,
                ));
                $this->fail('The invalid domain or direction should be rejected.');
            } catch (InvalidStockPostingException) {
                $this->assertDatabaseCount('part_stock_movements', 0);
            }
        }
    }

    public function test_a_posted_movement_is_corrected_by_one_idempotent_reversal(): void
    {
        [, , $finishedProduct, $warehouse, $branch] = $this->foundation();
        $service = app(FinishedProductStockPostingService::class);

        $opening = $service->post($this->posting(
            $finishedProduct->id,
            $branch->id,
            $warehouse->id,
            StockMovementType::Opening,
            StockDirection::In,
            8,
            'opening:finished:1',
        ));

        $reversal = $service->reverse($opening->movement->id, 'reversal:finished:1');
        $retry = $service->reverse($opening->movement->id, 'reversal:finished:1');

        $this->assertSame(StockMovementType::Reversal, $reversal->movement->movement_type);
        $this->assertSame(StockDirection::Out, $reversal->movement->direction);
        $this->assertSame($opening->movement->id, $reversal->movement->reversal_of_id);
        $this->assertSame('0.000000', $reversal->balance->quantity);
        $this->assertTrue($retry->wasAlreadyPosted);
        $this->assertDatabaseCount('finished_product_stock_movements', 2);

        $this->expectException(StockMovementAlreadyReversedException::class);
        $service->reverse($opening->movement->id, 'reversal:finished:other');
    }

    private function posting(
        int $itemId,
        int $branchId,
        int $warehouseId,
        StockMovementType $movementType,
        StockDirection $direction,
        int|float|string $quantity,
        string $postingKey,
    ): StockPostingData {
        return new StockPostingData(
            itemId: $itemId,
            branchId: $branchId,
            warehouseId: $warehouseId,
            movementType: $movementType,
            direction: $direction,
            quantity: $quantity,
            sourceType: 'test_document',
            sourceId: 1,
            postingKey: $postingKey,
            sourceItemId: 1,
        );
    }

    /** @return array{RawMaterial, Part, FinishedProduct, Warehouse, Branch} */
    private function foundation(): array
    {
        $unit = Unit::create(['name' => 'Piece', 'code' => 'PCS', 'precision' => 0, 'is_active' => true]);
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

        $rawMaterial = RawMaterial::create([
            'material_code' => 'RM-001',
            'name' => 'Copper Wire',
            'raw_material_category_id' => $rawCategory->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'MATERIAL',
            'is_active' => true,
        ]);
        $part = Part::create([
            'part_code' => 'PT-001',
            'name' => 'Transformer',
            'part_category_id' => $partCategory->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => 'BOTH',
            'is_active' => true,
        ]);
        $finishedProduct = FinishedProduct::create([
            'product_code' => 'FG-001',
            'name' => 'Voltage Stabilizer',
            'finished_product_category_id' => $finishedCategory->id,
            'base_unit_id' => $unit->id,
            'is_active' => true,
        ]);

        return [$rawMaterial, $part, $finishedProduct, $warehouse, $branch];
    }
}
