<?php

namespace Tests\Feature;

use App\Models\backend\Branch;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Supplier;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlyPurchaseReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyPurchaseReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_purchase_report_filters_posted_receipts_by_period(): void
    {
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Main Store',
            'code' => 'STORE',
            'type' => 'store',
            'is_default' => true,
            'is_active' => true,
        ]);
        $otherWarehouse = Warehouse::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Second Store',
            'code' => 'STORE-2',
            'type' => 'store',
            'is_default' => false,
            'is_active' => true,
        ]);
        $supplier = Supplier::query()->create([
            'code' => 'SUP-MONTH',
            'name' => 'Monthly Supplier',
            'is_active' => true,
        ]);
        $unit = Unit::query()->create([
            'name' => 'Piece',
            'code' => 'PCS',
            'precision' => 0,
            'is_active' => true,
        ]);
        $category = RawMaterialCategory::query()->create([
            'code' => 'RM',
            'name' => 'Raw Materials',
            'is_active' => true,
        ]);
        $raw = RawMaterial::query()->create([
            'material_code' => 'RM-PURCHASE-MONTH',
            'name' => 'Purchase Item',
            'raw_material_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'MATERIAL',
            'is_active' => true,
        ]);
        $period = ReportingPeriod::query()->create([
            'year' => 2026,
            'month' => 11,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'status' => 'OPEN',
        ]);

        $receiptId = DB::table('purchase_receipt')->insertGetId([
            'receipt_no' => 'PR-MONTH-001',
            'purchase_order_id' => null,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-11-15',
            'status' => 'POSTED',
            'subtotal' => '200.0000',
            'discount' => '0.0000',
            'tax' => '0.0000',
            'total' => '200.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_receipt_item')->insert([
            'purchase_receipt_id' => $receiptId,
            'purchase_order_item_id' => null,
            'raw_material_id' => $raw->id,
            'part_id' => null,
            'unit_id' => $unit->id,
            'conversion_to_base' => '1.000000',
            'quantity' => '1.000000',
            'base_quantity' => '1.000000',
            'unit_rate' => '200.0000',
            'line_total' => '200.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherReceiptId = DB::table('purchase_receipt')->insertGetId([
            'receipt_no' => 'PR-MONTH-OTHER-WAREHOUSE',
            'purchase_order_id' => null,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $otherWarehouse->id,
            'receipt_date' => '2026-11-16',
            'status' => 'POSTED',
            'subtotal' => '300.0000',
            'discount' => '0.0000',
            'tax' => '0.0000',
            'total' => '300.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentTypeId = DB::table('payment_types')->insertGetId([
            'name' => 'Cash',
            'slug' => 'cash',
            'allows_change' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_payment')->insert([
            [
                'payment_no' => 'PPAY-MONTH-001',
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'purchase_receipt_id' => $receiptId,
                'payment_type_id' => $paymentTypeId,
                'payment_date' => '2026-11-17',
                'amount' => '150.0000',
                'status' => 'POSTED',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'payment_no' => 'PPAY-MONTH-OTHER-WAREHOUSE',
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'purchase_receipt_id' => $otherReceiptId,
                'payment_type_id' => $paymentTypeId,
                'payment_date' => '2026-11-17',
                'amount' => '300.0000',
                'status' => 'POSTED',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $report = app(MonthlyPurchaseReportService::class)->report(
            $period->id,
            $branch->id,
            $warehouse->id,
            $supplier->id,
        );

        $this->assertSame('200.0000', $report['receipt_total']);
        $this->assertSame('200.0000', $report['net_purchase']);
        $this->assertSame('0.0000', $report['return_total']);
        $this->assertSame('150.0000', $report['payment_total']);
        $this->assertCount(1, $report['lines']);
    }
}
