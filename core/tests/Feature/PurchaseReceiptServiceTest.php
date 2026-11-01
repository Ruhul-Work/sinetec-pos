<?php

namespace Tests\Feature;

use App\Enums\Purchase\PurchaseOrderStatus;
use App\Enums\Purchase\PurchaseReceiptStatus;
use App\Exceptions\Purchase\InvalidPurchaseReceiptException;
use App\Http\Controllers\backend\PurchaseReceiptController;
use App\Models\backend\Branch;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Supplier;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Services\Purchase\PurchaseOrderService;
use App\Services\Purchase\PurchaseReceiptService;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseReceiptServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_draft_recalculates_totals_and_post_adds_mixed_stock_once(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(10, 26, 30));
        [$raw,$part,$supplier,$warehouse,$piece] = $this->foundation();
        $service = app(PurchaseReceiptService::class);
        $header = $this->header($supplier->id, $warehouse->id, discountType: 'PERCENT', discountValue: 10);
        $header['supplier_invoice_path'] = 'uploads/purchase-receipts/invoices/2026/11/01/test.pdf';
        $draft = $service->createDraft($header, [
            $this->line('raw_material', $raw->id, $piece->id, 4, 25),
            $this->line('part', $part->id, $piece->id, 2, 50),
        ]);

        $this->assertSame(PurchaseReceiptStatus::Draft, $draft->status);
        $this->assertSame($header['supplier_invoice_path'], $draft->supplier_invoice_path);
        $this->assertSame('200.0000', $draft->subtotal);
        $this->assertSame('20.0000', $draft->discount);
        $this->assertDatabaseCount('raw_material_stock_movements', 0);

        $posted = $service->post($draft);
        $retry = $service->post($posted);

        $this->assertSame(PurchaseReceiptStatus::Posted, $retry->status);
        $this->assertDatabaseHas('raw_material_stock_balances', ['raw_material_id' => $raw->id, 'warehouse_id' => $warehouse->id, 'quantity' => 4]);
        $this->assertDatabaseHas('part_stock_balances', ['part_id' => $part->id, 'warehouse_id' => $warehouse->id, 'quantity' => 2]);
        $this->assertDatabaseHas('raw_material_stock_movements', ['raw_material_id' => $raw->id, 'movement_at' => '2026-10-31 10:26:30']);
        $this->assertDatabaseHas('part_stock_movements', ['part_id' => $part->id, 'movement_at' => '2026-10-31 10:26:30']);
        $this->assertDatabaseCount('raw_material_stock_movements', 1);
        $this->assertDatabaseCount('part_stock_movements', 1);
        $this->travelBack();
    }

    public function test_purchase_order_can_be_received_partially_then_fully(): void
    {
        [$raw,,$supplier,$warehouse,$piece] = $this->foundation();
        $orders = app(PurchaseOrderService::class);
        $receipts = app(PurchaseReceiptService::class);
        $order = $orders->approve($orders->createDraft($this->orderHeader($supplier->id, $warehouse->id), [$this->line('raw_material', $raw->id, $piece->id, 5, 20)]));
        $orderItem = $order->items->firstOrFail();

        $poForm = app(PurchaseReceiptController::class)->create(Request::create('/purchase/receipts/create', 'GET', [
            'purchase_order_id' => $order->id,
        ]));
        $poHtml = $poForm->render();
        $this->assertStringContainsString('Pending PO Items', $poHtml);
        $this->assertStringContainsString('Ordered', $poHtml);
        $this->assertStringContainsString('Received', $poHtml);
        $this->assertStringContainsString('Pending', $poHtml);

        $first = $receipts->createDraft($this->header($supplier->id, $warehouse->id, $order->id), [$this->line('raw_material', $raw->id, $piece->id, 2, 21, $orderItem->id)]);
        $receipts->post($first);
        $this->assertDatabaseHas('purchase_order', ['id' => $order->id, 'status' => PurchaseOrderStatus::Partial->value]);
        $this->assertDatabaseHas('purchase_order_item', ['id' => $orderItem->id, 'received_base_quantity' => 2]);

        $second = $receipts->createDraft($this->header($supplier->id, $warehouse->id, $order->id), [$this->line('raw_material', $raw->id, $piece->id, 3, 22, $orderItem->id)]);
        $receipts->post($second);
        $this->assertDatabaseHas('purchase_order', ['id' => $order->id, 'status' => PurchaseOrderStatus::Received->value]);
        $this->assertDatabaseHas('purchase_order_item', ['id' => $orderItem->id, 'received_base_quantity' => 5]);
        $this->assertDatabaseHas('raw_material_stock_balances', ['raw_material_id' => $raw->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5]);
    }

    public function test_alternative_purchase_unit_posts_base_quantity_and_base_unit_cost(): void
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

        $receipt = app(PurchaseReceiptService::class)->createDraft(
            $this->header($supplier->id, $warehouse->id),
            [$this->line('raw_material', $raw->id, $box->id, 2, 120)],
        );
        app(PurchaseReceiptService::class)->post($receipt);

        $this->assertDatabaseHas('raw_material_stock_balances', [
            'raw_material_id' => $raw->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 24,
        ]);
        $this->assertDatabaseHas('raw_material_stock_movements', [
            'raw_material_id' => $raw->id,
            'quantity' => 24,
            'unit_cost' => 10,
            'movement_type' => 'PURCHASE_RECEIPT',
        ]);
    }

    public function test_receipt_cannot_exceed_purchase_order_pending_quantity(): void
    {
        [$raw,,$supplier,$warehouse,$piece] = $this->foundation();
        $orders = app(PurchaseOrderService::class);
        $order = $orders->approve($orders->createDraft($this->orderHeader($supplier->id, $warehouse->id), [$this->line('raw_material', $raw->id, $piece->id, 5, 20)]));
        $this->expectException(InvalidPurchaseReceiptException::class);
        $this->expectExceptionMessage('exceeds the currently pending');
        app(PurchaseReceiptService::class)->createDraft($this->header($supplier->id, $warehouse->id, $order->id), [$this->line('raw_material', $raw->id, $piece->id, 6, 20, $order->items->firstOrFail()->id)]);
    }

    public function test_purchase_receipt_routes_have_independent_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);
        $id = DB::table('permissions')->where('key', 'purchase.receipts')->value('id');
        $this->assertNotNull($id);
        $this->assertDatabaseHas('permission_routes', ['permission_id' => $id, 'route_name' => 'purchase.receipts.index']);
        $this->assertDatabaseHas('permission_routes', ['permission_id' => $id, 'route_name' => 'purchase.receipts.post']);
        $this->assertSame(11, DB::table('permission_routes')->where('permission_id', $id)->count());
    }

    public function test_receipt_list_create_and_item_queries_use_valid_aliases(): void
    {
        [$raw, , $supplier, $warehouse, $piece] = $this->foundation();
        app(PurchaseReceiptService::class)->createDraft(
            $this->header($supplier->id, $warehouse->id),
            [$this->line('raw_material', $raw->id, $piece->id, 1, 10)],
        );

        $controller = app(PurchaseReceiptController::class);
        $list = $controller->listAjax(Request::create('/purchase/receipts/list', 'POST', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))->getData(true);
        $create = $controller->create(Request::create('/purchase/receipts/create', 'GET'));
        $items = $controller->itemSelect2(Request::create('/purchase/receipts/items/select2', 'GET', [
            'domain' => 'raw_material',
            'supplier_id' => $supplier->id,
        ]))->getData(true);

        $this->assertSame(1, $list['iTotalRecords']);
        $this->assertSame('backend.modules.purchase_receipt.form', $create->name());
        $this->assertSame('Main Branch', $create->getData()['warehouses']->first()->branch_name);
        $createHtml = $create->render();
        $this->assertStringContainsString('Select Received Items', $createHtml);
        $this->assertStringContainsString('enctype="multipart/form-data"', $createHtml);
        $this->assertStringContainsString('name="supplier_invoice_file"', $createHtml);
        $this->assertSame($raw->id, $items['results'][0]['id']);
        $this->assertSame('PCS', $items['results'][0]['unit_code']);
        $this->assertSame($piece->id, $items['results'][0]['base_unit_id']);
    }

    private function header(int $supplierId, int $warehouseId, ?int $orderId = null, string $discountType = 'FLAT', int|float|string $discountValue = 0): array
    {
        return ['purchase_order_id' => $orderId, 'supplier_id' => $supplierId, 'warehouse_id' => $warehouseId, 'receipt_date' => '2026-10-31', 'supplier_invoice_no' => 'INV-1001', 'discount_type' => $discountType, 'discount_value' => $discountValue, 'tax' => 0, 'note' => 'Test receipt'];
    }

    private function orderHeader(int $supplierId, int $warehouseId): array
    {
        return ['supplier_id' => $supplierId, 'warehouse_id' => $warehouseId, 'order_date' => '2026-10-30', 'expected_date' => null, 'discount_type' => 'FLAT', 'discount_value' => 0, 'tax' => 0];
    }

    private function line(string $domain, int $itemId, int $unitId, int|float|string $quantity, int|float|string $rate, ?int $orderItemId = null): array
    {
        return ['purchase_order_item_id' => $orderItemId, 'domain' => $domain, 'item_id' => $itemId, 'unit_id' => $unitId, 'quantity' => $quantity, 'unit_rate' => $rate];
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
