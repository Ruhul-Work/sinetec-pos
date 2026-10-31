<?php

namespace Tests\Feature;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Http\Controllers\backend\StockLedgerController;
use App\Models\backend\Branch;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Services\Inventory\PartStockPostingService;
use App\Services\Inventory\RawMaterialStockPostingService;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_combines_domains_and_calculates_running_balance(): void
    {
        [$raw, $part, $warehouse, $branch] = $this->foundation();
        $this->postRaw($raw->id, $warehouse->id, $branch->id, StockDirection::In, 10, '2026-10-01 09:00:00', 'raw-in');
        $this->postRaw($raw->id, $warehouse->id, $branch->id, StockDirection::Out, 3, '2026-10-02 09:00:00', 'raw-out');
        app(PartStockPostingService::class)->post(new StockPostingData(
            itemId: $part->id,
            branchId: $branch->id,
            warehouseId: $warehouse->id,
            movementType: StockMovementType::Adjustment,
            direction: StockDirection::In,
            quantity: 4,
            sourceType: 'test_source',
            sourceId: 3,
            postingKey: 'ledger:part-in',
            movementAt: '2026-10-03 09:00:00',
        ));

        $response = app(StockLedgerController::class)->listAjax($this->request());
        $payload = $response->getData(true);

        $this->assertSame(3, $payload['iTotalRecords']);
        $this->assertSame(3, $payload['iTotalDisplayRecords']);
        $this->assertCount(3, $payload['aaData']);
        $rawOut = collect($payload['aaData'])->first(fn (array $row) => str_contains($row[8], '3 PCS'));
        $this->assertNotNull($rawOut);
        $this->assertStringContainsString('7 PCS', $rawOut[10]);
    }

    public function test_date_filter_keeps_true_balance_from_movements_before_the_period(): void
    {
        [$raw, , $warehouse, $branch] = $this->foundation();
        $this->postRaw($raw->id, $warehouse->id, $branch->id, StockDirection::In, 10, '2026-09-30 09:00:00', 'before-period');
        $this->postRaw($raw->id, $warehouse->id, $branch->id, StockDirection::Out, 3, '2026-10-02 09:00:00', 'inside-period');

        $response = app(StockLedgerController::class)->listAjax($this->request([
            'domain' => 'raw_material',
            'item_id' => $raw->id,
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
        ]));
        $payload = $response->getData(true);

        $this->assertSame(1, $payload['iTotalDisplayRecords']);
        $this->assertStringContainsString('3 PCS', $payload['aaData'][0][8]);
        $this->assertStringContainsString('7 PCS', $payload['aaData'][0][10]);
    }

    public function test_stock_ledger_routes_have_an_independent_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);
        $permissionId = DB::table('permissions')->where('key', 'inventory.stock_ledger')->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(3, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
        $this->assertDatabaseHas('permission_routes', [
            'permission_id' => $permissionId,
            'route_name' => 'inventory.stock-ledger.index',
        ]);
        $this->assertDatabaseHas('permission_routes', [
            'permission_id' => $permissionId,
            'route_name' => 'inventory.stock-ledger.list.ajax',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function request(array $overrides = []): Request
    {
        return Request::create('/inventory/stock-ledger/list', 'POST', array_merge([
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'order' => [['column' => 1, 'dir' => 'desc']],
            'search' => ['value' => ''],
        ], $overrides));
    }

    private function postRaw(int $itemId, int $warehouseId, int $branchId, StockDirection $direction, int $quantity, string $movementAt, string $key): void
    {
        app(RawMaterialStockPostingService::class)->post(new StockPostingData(
            itemId: $itemId,
            branchId: $branchId,
            warehouseId: $warehouseId,
            movementType: StockMovementType::Adjustment,
            direction: $direction,
            quantity: $quantity,
            sourceType: 'test_source',
            sourceId: $direction === StockDirection::In ? 1 : 2,
            postingKey: 'ledger:'.$key,
            movementAt: $movementAt,
        ));
    }

    /** @return array{RawMaterial, Part, Warehouse, Branch} */
    private function foundation(): array
    {
        $piece = Unit::create(['name' => 'Piece', 'code' => 'PCS', 'precision' => 0, 'is_active' => true]);
        $rawCategory = RawMaterialCategory::create(['code' => 'RM', 'name' => 'Raw Materials', 'is_active' => true]);
        $partCategory = PartCategory::create(['code' => 'PART', 'name' => 'Parts', 'is_active' => true]);
        $branch = Branch::create(['name' => 'Main Branch', 'code' => 'BR-001', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Main Warehouse', 'code' => 'WH-001', 'type' => 'store', 'is_default' => true, 'is_active' => true]);
        $raw = RawMaterial::create(['material_code' => 'RM-001', 'name' => 'Copper Wire', 'raw_material_category_id' => $rawCategory->id, 'base_unit_id' => $piece->id, 'material_kind' => 'MATERIAL', 'is_active' => true]);
        $part = Part::create(['part_code' => 'PT-001', 'name' => 'Transformer', 'part_category_id' => $partCategory->id, 'base_unit_id' => $piece->id, 'procurement_mode' => 'BOTH', 'is_active' => true]);

        return [$raw, $part, $warehouse, $branch];
    }
}
