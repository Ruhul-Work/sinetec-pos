<?php

namespace Tests\Feature;

use App\Enums\Purchase\PurchaseReturnStatus;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Purchase\InvalidPurchaseReturnException;
use App\Http\Controllers\backend\PurchaseReturnController;
use App\Http\Controllers\backend\StockLedgerController;
use App\Models\backend\Branch;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Supplier;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Models\Purchase\PurchaseReceipt;
use App\Services\Purchase\PurchaseReceiptService;
use App\Services\Purchase\PurchaseReturnService;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_does_not_change_stock_and_post_removes_mixed_stock_once(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(10, 26, 30));
        [$raw, $part, $supplier, $warehouse, $piece] = $this->foundation();
        $receipt = $this->postedReceipt($supplier->id, $warehouse->id, [
            $this->receiptLine('raw_material', $raw->id, $piece->id, 4, 25),
            $this->receiptLine('part', $part->id, $piece->id, 2, 50),
        ]);
        $service = app(PurchaseReturnService::class);
        $draft = $service->createDraft($this->returnHeader($receipt->id), [
            $this->returnLine($receipt->items[0]->id, 1),
            $this->returnLine($receipt->items[1]->id, 1),
        ]);

        $this->assertSame(PurchaseReturnStatus::Draft, $draft->status);
        $this->assertSame('75.0000', $draft->total);
        $this->assertDatabaseHas('raw_material_stock_balances', ['raw_material_id' => $raw->id, 'quantity' => 4]);
        $this->assertDatabaseCount('raw_material_stock_movements', 1);

        $posted = $service->post($draft);
        $retry = $service->post($posted);

        $this->assertSame(PurchaseReturnStatus::Posted, $retry->status);
        $this->assertDatabaseHas('raw_material_stock_balances', ['raw_material_id' => $raw->id, 'warehouse_id' => $warehouse->id, 'quantity' => 3]);
        $this->assertDatabaseHas('part_stock_balances', ['part_id' => $part->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1]);
        $this->assertDatabaseHas('raw_material_stock_movements', ['source_type' => 'purchase_return', 'source_id' => $draft->id, 'direction' => 'OUT', 'movement_at' => '2026-11-01 10:26:30']);
        $this->assertDatabaseHas('part_stock_movements', ['source_type' => 'purchase_return', 'source_id' => $draft->id, 'direction' => 'OUT', 'movement_at' => '2026-11-01 10:26:30']);
        $this->assertDatabaseCount('raw_material_stock_movements', 2);
        $this->assertDatabaseCount('part_stock_movements', 2);
        $ledger = app(StockLedgerController::class)->listAjax(Request::create('/inventory/stock-ledger/list', 'POST', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'movement_type' => 'PURCHASE_RETURN',
        ]))->getData(true);
        $this->assertSame(2, $ledger['iTotalDisplayRecords']);
        $this->assertStringContainsString($draft->return_no, $ledger['aaData'][0][6]);
        $this->travelBack();
    }

    public function test_alternative_unit_return_uses_base_quantity_and_original_base_cost(): void
    {
        [$raw, , $supplier, $warehouse] = $this->foundation();
        $box = Unit::create(['name' => 'Box', 'code' => 'BOX', 'precision' => 0, 'is_active' => true]);
        DB::table('raw_material_units')->insert([
            'raw_material_id' => $raw->id,
            'unit_id' => $box->id,
            'conversion_to_base' => 12,
            'usage_type' => 'PURCHASE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $receipt = $this->postedReceipt($supplier->id, $warehouse->id, [
            $this->receiptLine('raw_material', $raw->id, $box->id, 2, 120),
        ]);
        $return = app(PurchaseReturnService::class)->createDraft(
            $this->returnHeader($receipt->id),
            [$this->returnLine($receipt->items->firstOrFail()->id, 1)],
        );

        app(PurchaseReturnService::class)->post($return);

        $this->assertDatabaseHas('raw_material_stock_balances', ['raw_material_id' => $raw->id, 'warehouse_id' => $warehouse->id, 'quantity' => 12]);
        $this->assertDatabaseHas('raw_material_stock_movements', [
            'source_type' => 'purchase_return',
            'source_id' => $return->id,
            'quantity' => 12,
            'unit_cost' => 10,
        ]);
    }

    public function test_post_rechecks_cumulative_returns_and_blocks_over_return(): void
    {
        [$raw, , $supplier, $warehouse, $piece] = $this->foundation();
        $receipt = $this->postedReceipt($supplier->id, $warehouse->id, [
            $this->receiptLine('raw_material', $raw->id, $piece->id, 5, 20),
        ]);
        $sourceId = $receipt->items->firstOrFail()->id;
        $service = app(PurchaseReturnService::class);
        $first = $service->createDraft($this->returnHeader($receipt->id), [$this->returnLine($sourceId, 3)]);
        $second = $service->createDraft($this->returnHeader($receipt->id), [$this->returnLine($sourceId, 3)]);
        $service->post($first);

        try {
            $service->post($second);
            $this->fail('The second overlapping return should not post.');
        } catch (InvalidPurchaseReturnException $exception) {
            $this->assertStringContainsString('exceeds', $exception->getMessage());
        }

        $this->assertDatabaseHas('purchase_return', ['id' => $second->id, 'status' => PurchaseReturnStatus::Draft->value]);
        $this->assertDatabaseHas('raw_material_stock_balances', ['raw_material_id' => $raw->id, 'quantity' => 2]);
        $this->assertDatabaseCount('raw_material_stock_movements', 2);
    }

    public function test_insufficient_stock_rolls_back_every_return_line(): void
    {
        [$raw, $part, $supplier, $warehouse, $piece] = $this->foundation();
        $receipt = $this->postedReceipt($supplier->id, $warehouse->id, [
            $this->receiptLine('raw_material', $raw->id, $piece->id, 2, 20),
            $this->receiptLine('part', $part->id, $piece->id, 2, 30),
        ]);
        $return = app(PurchaseReturnService::class)->createDraft($this->returnHeader($receipt->id), [
            $this->returnLine($receipt->items[0]->id, 1),
            $this->returnLine($receipt->items[1]->id, 1),
        ]);
        DB::table('part_stock_balances')->where('part_id', $part->id)->update(['quantity' => 0]);

        try {
            app(PurchaseReturnService::class)->post($return);
            $this->fail('Posting should fail when one return line has insufficient stock.');
        } catch (InsufficientStockException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('purchase_return', ['id' => $return->id, 'status' => PurchaseReturnStatus::Draft->value]);
        $this->assertDatabaseHas('raw_material_stock_balances', ['raw_material_id' => $raw->id, 'quantity' => 2]);
        $this->assertDatabaseMissing('raw_material_stock_movements', ['source_type' => 'purchase_return', 'source_id' => $return->id]);
        $this->assertDatabaseMissing('part_stock_movements', ['source_type' => 'purchase_return', 'source_id' => $return->id]);
    }

    public function test_return_date_cannot_be_before_receipt_date(): void
    {
        [$raw, , $supplier, $warehouse, $piece] = $this->foundation();
        $receipt = $this->postedReceipt($supplier->id, $warehouse->id, [
            $this->receiptLine('raw_material', $raw->id, $piece->id, 1, 20),
        ]);

        $this->expectException(InvalidPurchaseReturnException::class);
        $this->expectExceptionMessage('before the source receipt date');
        app(PurchaseReturnService::class)->createDraft(
            [...$this->returnHeader($receipt->id), 'return_date' => '2026-10-30'],
            [$this->returnLine($receipt->items->firstOrFail()->id, 1)],
        );
    }

    public function test_routes_permission_list_and_create_form_are_available(): void
    {
        [$raw, , $supplier, $warehouse, $piece] = $this->foundation();
        $receipt = $this->postedReceipt($supplier->id, $warehouse->id, [
            $this->receiptLine('raw_material', $raw->id, $piece->id, 2, 20),
        ]);
        $return = app(PurchaseReturnService::class)->createDraft(
            $this->returnHeader($receipt->id),
            [$this->returnLine($receipt->items->firstOrFail()->id, 1)],
        );
        $this->seed(CorePermissionSeeder::class);
        $permissionId = DB::table('permissions')->where('key', 'purchase.returns')->value('id');

        $controller = app(PurchaseReturnController::class);
        $list = $controller->listAjax(Request::create('/purchase/returns/list', 'POST', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))->getData(true);
        $create = $controller->create(Request::create('/purchase/returns/create', 'GET', [
            'purchase_receipt_id' => $receipt->id,
        ]));

        $this->assertNotNull($permissionId);
        $this->assertSame(9, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
        $this->assertDatabaseHas('permission_routes', ['permission_id' => $permissionId, 'route_name' => 'purchase.returns.post']);
        $this->assertSame(1, $list['iTotalRecords']);
        $this->assertStringContainsString($return->return_no, $list['aaData'][0][1]);
        $this->assertSame('backend.modules.purchase_return.form', $create->name());
        $html = $create->render();
        $this->assertStringContainsString('Returnable Receipt Items', $html);
        $this->assertStringContainsString('Items to Return', $html);
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function postedReceipt(int $supplierId, int $warehouseId, array $lines): PurchaseReceipt
    {
        $service = app(PurchaseReceiptService::class);
        $draft = $service->createDraft([
            'purchase_order_id' => null,
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'receipt_date' => '2026-11-01',
            'supplier_invoice_no' => null,
            'discount_type' => 'FLAT',
            'discount_value' => 0,
            'tax' => 0,
            'note' => 'Return test source',
        ], $lines);

        return $service->post($draft);
    }

    /** @return array<string, mixed> */
    private function receiptLine(string $domain, int $itemId, int $unitId, int|float|string $quantity, int|float|string $rate): array
    {
        return ['purchase_order_item_id' => null, 'domain' => $domain, 'item_id' => $itemId, 'unit_id' => $unitId, 'quantity' => $quantity, 'unit_rate' => $rate];
    }

    /** @return array<string, mixed> */
    private function returnHeader(int $receiptId): array
    {
        return ['purchase_receipt_id' => $receiptId, 'return_date' => '2026-11-01', 'reason' => 'Damaged item returned to supplier'];
    }

    /** @return array<string, mixed> */
    private function returnLine(int $receiptItemId, int|float|string $quantity): array
    {
        return ['purchase_receipt_item_id' => $receiptItemId, 'quantity' => $quantity];
    }

    private function foundation(): array
    {
        $piece = Unit::create(['name' => 'Piece', 'code' => 'PCS', 'precision' => 0, 'is_active' => true]);
        $rawCategory = RawMaterialCategory::create(['code' => 'RM', 'name' => 'Raw Materials', 'is_active' => true]);
        $partCategory = PartCategory::create(['code' => 'PART', 'name' => 'Parts', 'is_active' => true]);
        $branch = Branch::create(['name' => 'Main Branch', 'code' => 'BR-001', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Main Warehouse', 'code' => 'WH-001', 'type' => 'store', 'is_default' => true, 'is_active' => true]);
        $supplier = Supplier::create(['code' => 'SUP-001', 'name' => 'Supplier', 'is_active' => true]);
        $raw = RawMaterial::create(['material_code' => 'RM-001', 'name' => 'Copper', 'raw_material_category_id' => $rawCategory->id, 'base_unit_id' => $piece->id, 'material_kind' => 'MATERIAL', 'is_active' => true]);
        $part = Part::create(['part_code' => 'PT-001', 'name' => 'Transformer', 'part_category_id' => $partCategory->id, 'base_unit_id' => $piece->id, 'procurement_mode' => 'BOTH', 'is_active' => true]);

        return [$raw, $part, $supplier, $warehouse, $piece];
    }
}
