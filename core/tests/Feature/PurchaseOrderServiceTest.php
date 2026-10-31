<?php

namespace Tests\Feature;

use App\Enums\Purchase\PurchaseOrderStatus;
use App\Exceptions\Purchase\InvalidPurchaseOrderException;
use App\Http\Controllers\backend\PurchaseOrderController;
use App\Models\backend\Branch;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Supplier;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Services\Purchase\PurchaseOrderService;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_draft_uses_selected_purchase_unit(): void
    {
        [$raw, $part, $supplier, $warehouse, $piece, $box] = $this->foundation();
        $service = app(PurchaseOrderService::class);

        $order = $service->createDraft(
            $this->header($supplier->id, $warehouse->id, discountValue: '10', tax: '5'),
            [
                $this->line('raw_material', $raw->id, $box->id, '2', '100'),
                $this->line('part', $part->id, $piece->id, '3', '50.25'),
            ],
        );

        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertSame('350.7500', $order->subtotal);
        $this->assertSame('345.7500', $order->total);
        $this->assertDatabaseHas('purchase_order_item', [
            'purchase_order_id' => $order->id,
            'raw_material_id' => $raw->id,
            'part_id' => null,
            'unit_id' => $box->id,
            'conversion_to_base' => 12,
            'quantity' => 2,
            'base_quantity' => 24,
            'unit_rate' => 100,
            'line_total' => 200,
        ]);
        $this->assertDatabaseHas('purchase_order_item', [
            'purchase_order_id' => $order->id,
            'raw_material_id' => null,
            'part_id' => $part->id,
            'quantity' => 3,
            'base_quantity' => 3,
            'line_total' => 150.75,
        ]);
        $this->assertDatabaseCount('raw_material_stock_movements', 0);
        $this->assertDatabaseCount('part_stock_movements', 0);
        $this->assertDatabaseCount('raw_material_stock_balances', 0);
        $this->assertDatabaseCount('part_stock_balances', 0);
    }

    public function test_percentage_discount_is_calculated_and_snapshotted(): void
    {
        [$raw, , $supplier, $warehouse, $piece] = $this->foundation();

        $order = app(PurchaseOrderService::class)->createDraft(
            $this->header($supplier->id, $warehouse->id, discountValue: '12.5', discountType: 'PERCENT'),
            [$this->line('raw_material', $raw->id, $piece->id, 4, 100)],
        );

        $this->assertSame('PERCENT', $order->discount_type);
        $this->assertSame('12.5000', $order->discount_value);
        $this->assertSame('50.0000', $order->discount);
        $this->assertSame('350.0000', $order->total);
    }

    public function test_percentage_discount_cannot_exceed_one_hundred(): void
    {
        [$raw, , $supplier, $warehouse, $piece] = $this->foundation();

        $this->expectException(InvalidPurchaseOrderException::class);
        $this->expectExceptionMessage('cannot be greater than 100');

        app(PurchaseOrderService::class)->createDraft(
            $this->header($supplier->id, $warehouse->id, discountValue: '100.01', discountType: 'PERCENT'),
            [$this->line('raw_material', $raw->id, $piece->id, 1, 100)],
        );
    }

    public function test_unmapped_item_can_be_purchased_from_an_active_supplier(): void
    {
        [$raw, , , $warehouse, $piece] = $this->foundation();
        $otherSupplier = Supplier::create(['code' => 'SUP-002', 'name' => 'Other Supplier', 'is_active' => true]);

        $order = app(PurchaseOrderService::class)->createDraft(
            $this->header($otherSupplier->id, $warehouse->id),
            [$this->line('raw_material', $raw->id, $piece->id, 1, 10)],
        );

        $this->assertSame($otherSupplier->id, $order->supplier_id);
        $this->assertDatabaseHas('purchase_order_item', [
            'purchase_order_id' => $order->id,
            'raw_material_id' => $raw->id,
            'quantity' => 1,
            'unit_rate' => 10,
        ]);
    }

    public function test_make_only_part_cannot_be_purchased(): void
    {
        [, , $supplier, $warehouse, $piece] = $this->foundation();
        $category = PartCategory::query()->firstOrFail();
        $makeOnly = Part::create([
            'part_code' => 'PT-MAKE', 'name' => 'Internal Coil', 'part_category_id' => $category->id,
            'base_unit_id' => $piece->id, 'procurement_mode' => 'MAKE', 'is_active' => true,
        ]);
        DB::table('part_suppliers')->insert([
            'part_id' => $makeOnly->id, 'supplier_id' => $supplier->id, 'lead_days' => 0,
            'is_preferred' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(InvalidPurchaseOrderException::class);
        $this->expectExceptionMessage('non-purchasable item');

        app(PurchaseOrderService::class)->createDraft(
            $this->header($supplier->id, $warehouse->id),
            [$this->line('part', $makeOnly->id, $piece->id, 1, 10)],
        );
    }

    public function test_approval_makes_order_read_only_without_changing_stock(): void
    {
        [$raw, , $supplier, $warehouse, $piece] = $this->foundation();
        $service = app(PurchaseOrderService::class);
        $draft = $service->createDraft(
            $this->header($supplier->id, $warehouse->id),
            [$this->line('raw_material', $raw->id, $piece->id, 5, 12.5)],
        );
        $approved = $service->approve($draft);

        $this->assertSame(PurchaseOrderStatus::Approved, $approved->status);
        $this->assertNotNull($approved->approved_at);
        $this->assertDatabaseCount('raw_material_stock_movements', 0);

        $this->expectException(InvalidPurchaseOrderException::class);
        $service->updateDraft(
            $approved,
            $this->header($supplier->id, $warehouse->id),
            [$this->line('raw_material', $raw->id, $piece->id, 6, 12.5)],
        );
    }

    public function test_duplicate_item_line_is_rejected_atomically(): void
    {
        [$raw, , $supplier, $warehouse, $piece] = $this->foundation();

        try {
            app(PurchaseOrderService::class)->createDraft(
                $this->header($supplier->id, $warehouse->id),
                [
                    $this->line('raw_material', $raw->id, $piece->id, 1, 10),
                    $this->line('raw_material', $raw->id, $piece->id, 2, 10),
                ],
            );
            $this->fail('Duplicate item lines should be rejected.');
        } catch (InvalidPurchaseOrderException $exception) {
            $this->assertStringContainsString('cannot be entered more than once', $exception->getMessage());
            $this->assertDatabaseCount('purchase_order', 0);
            $this->assertDatabaseCount('purchase_order_item', 0);
        }
    }

    public function test_purchase_order_routes_have_an_independent_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);
        $permissionId = DB::table('permissions')->where('key', 'purchase.orders')->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(12, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
        $this->assertDatabaseHas('permission_routes', ['permission_id' => $permissionId, 'route_name' => 'purchase.orders.index']);
        $this->assertDatabaseHas('permission_routes', ['permission_id' => $permissionId, 'route_name' => 'purchase.orders.approve']);
        $this->assertDatabaseHas('permission_routes', ['permission_id' => $permissionId, 'route_name' => 'purchase.orders.cancel']);
    }

    public function test_supplier_catalog_returns_mapped_and_unmapped_purchasable_items(): void
    {
        [$raw, $part, $supplier] = $this->foundation();
        $controller = app(PurchaseOrderController::class);
        $rawCategory = RawMaterialCategory::query()->firstOrFail();
        $piece = Unit::query()->where('code', 'PCS')->firstOrFail();
        $unmappedRaw = RawMaterial::create([
            'material_code' => 'RM-002', 'name' => 'Aluminium Sheet',
            'raw_material_category_id' => $rawCategory->id, 'base_unit_id' => $piece->id,
            'material_kind' => 'MATERIAL', 'is_active' => true,
        ]);

        $rawResponse = $controller->itemSelect2(Request::create('/purchase/orders/items/select2', 'GET', [
            'supplier_id' => $supplier->id,
            'domain' => 'raw_material',
        ]));
        $rawData = $rawResponse->getData(true);
        $rawResults = collect($rawData['results'])->keyBy('id');

        $this->assertCount(2, $rawData['results']);
        $this->assertTrue($rawResults[$raw->id]['is_mapped']);
        $this->assertTrue($rawResults[$raw->id]['is_preferred']);
        $this->assertFalse($rawResults[$unmappedRaw->id]['is_mapped']);
        $this->assertSame('raw_material', $rawResults[$unmappedRaw->id]['domain']);
        $this->assertSame('PCS', $rawResults[$unmappedRaw->id]['unit_code']);
        $this->assertNotEmpty($rawResults[$unmappedRaw->id]['image_url']);

        $partResponse = $controller->itemSelect2(Request::create('/purchase/orders/items/select2', 'GET', [
            'supplier_id' => $supplier->id,
            'domain' => 'part',
        ]));
        $partData = $partResponse->getData(true);

        $this->assertCount(1, $partData['results']);
        $this->assertSame($part->id, $partData['results'][0]['id']);
        $this->assertSame('part', $partData['results'][0]['domain']);
    }

    /** @return array<string, mixed> */
    private function header(
        int $supplierId,
        int $warehouseId,
        int|float|string $discountValue = 0,
        int|float|string $tax = 0,
        string $discountType = 'FLAT',
    ): array {
        return [
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-10-29',
            'expected_date' => '2026-11-05',
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'tax' => $tax,
            'note' => 'Test purchase order',
        ];
    }

    /** @return array<string, mixed> */
    private function line(string $domain, int $itemId, int $unitId, int|float|string $quantity, int|float|string $unitRate): array
    {
        return [
            'domain' => $domain,
            'item_id' => $itemId,
            'unit_id' => $unitId,
            'quantity' => $quantity,
            'unit_rate' => $unitRate,
        ];
    }

    /** @return array{RawMaterial, Part, Supplier, Warehouse, Unit, Unit} */
    private function foundation(): array
    {
        $piece = Unit::create(['name' => 'Piece', 'code' => 'PCS', 'precision' => 0, 'is_active' => true]);
        $box = Unit::create(['name' => 'Box', 'code' => 'BOX', 'precision' => 0, 'is_active' => true]);
        $rawCategory = RawMaterialCategory::create(['code' => 'RM', 'name' => 'Raw Materials', 'is_active' => true]);
        $partCategory = PartCategory::create(['code' => 'PART', 'name' => 'Parts', 'is_active' => true]);
        $branch = Branch::create(['name' => 'Main Branch', 'code' => 'BR-001', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Main Warehouse', 'code' => 'WH-001', 'type' => 'store', 'is_default' => true, 'is_active' => true]);
        $supplier = Supplier::create(['code' => 'SUP-001', 'name' => 'Preferred Supplier', 'is_active' => true]);
        $raw = RawMaterial::create(['material_code' => 'RM-001', 'name' => 'Copper Wire', 'raw_material_category_id' => $rawCategory->id, 'base_unit_id' => $piece->id, 'material_kind' => 'MATERIAL', 'is_active' => true]);
        $part = Part::create(['part_code' => 'PT-001', 'name' => 'Transformer', 'part_category_id' => $partCategory->id, 'base_unit_id' => $piece->id, 'procurement_mode' => 'BOTH', 'is_active' => true]);

        DB::table('raw_material_suppliers')->insert([
            'raw_material_id' => $raw->id, 'supplier_id' => $supplier->id, 'supplier_code' => 'COPPER-A',
            'lead_days' => 3, 'is_preferred' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('part_suppliers')->insert([
            'part_id' => $part->id, 'supplier_id' => $supplier->id, 'supplier_code' => 'TRANS-A',
            'lead_days' => 5, 'is_preferred' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('raw_material_units')->insert([
            'raw_material_id' => $raw->id, 'unit_id' => $box->id, 'conversion_to_base' => 12,
            'usage_type' => 'PURCHASE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$raw, $part, $supplier, $warehouse, $piece, $box];
    }
}
