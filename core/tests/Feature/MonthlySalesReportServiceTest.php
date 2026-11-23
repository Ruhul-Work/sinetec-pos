<?php

namespace Tests\Feature;

use App\Models\backend\Branch;
use App\Models\backend\Customer;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlySalesReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlySalesReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_reconciles_sales_returns_receipts_and_split_payment_sources(): void
    {
        $setup = $this->setupSalesReport();
        $saleId = $this->createSale(
            $setup,
            $setup['warehouse']->id,
            'SALE-MONTH-001',
            '2026-11-10 10:00:00',
            'POSTED',
            '1000.0000',
            '900.0000',
            '0.0000',
        );
        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'finished_product_id' => $setup['product']->id,
            'unit_id' => $setup['unit']->id,
            'conversion_to_base' => '1.000000',
            'quantity' => '2.000000',
            'base_quantity' => '2.000000',
            'unit_price' => '500.0000',
            'discount' => '0.0000',
            'tax' => '0.0000',
            'line_total' => '1000.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createPayment(
            $saleId,
            $setup['payment_type_id'],
            null,
            'PAY-INITIAL-001',
            '2026-11-10 10:05:00',
            '800.0000',
        );

        $receiptId = DB::table('customer_receipts')->insertGetId([
            'receipt_no' => 'CR-MONTH-001',
            'branch_id' => $setup['branch']->id,
            'customer_id' => $setup['customer']->id,
            'sale_id' => $saleId,
            'receipt_at' => '2026-11-15 11:00:00',
            'amount' => '150.0000',
            'due_before' => '200.0000',
            'due_after' => '50.0000',
            'status' => 'POSTED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createPayment(
            $saleId,
            $setup['payment_type_id'],
            $receiptId,
            'PAY-RECEIPT-001',
            '2026-11-15 11:00:00',
            '150.0000',
        );

        DB::table('sale_returns')->insert([
            'return_no' => 'SRT-MONTH-001',
            'sale_id' => $saleId,
            'branch_id' => $setup['branch']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'return_at' => '2026-11-20 09:00:00',
            'status' => 'POSTED',
            'subtotal' => '100.0000',
            'discount' => '0.0000',
            'tax' => '0.0000',
            'total' => '100.0000',
            'due_adjustment' => '50.0000',
            'refund_amount' => '50.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createOtherWarehouseTransactions($setup);

        $report = app(MonthlySalesReportService::class)->report(
            $setup['period']->id,
            $setup['branch']->id,
            $setup['warehouse']->id,
            $setup['customer']->id,
            'POS',
        );

        $this->assertCount(1, $report['sales']);
        $this->assertCount(1, $report['returns']);
        $this->assertCount(1, $report['receipts']);
        $this->assertCount(1, $report['lines']);
        $this->assertSame('1000.0000', $report['sale_total']);
        $this->assertSame('100.0000', $report['return_total']);
        $this->assertSame('900.0000', $report['net_sales']);
        $this->assertSame('50.0000', $report['refund_total']);
        $this->assertSame('50.0000', $report['due_adjustment']);
        $this->assertSame('150.0000', $report['receipt_total']);
        $this->assertSame('800.0000', $report['initial_payment_total']);
        $this->assertSame('900.0000', $report['net_collected']);
        $this->assertSame('900.0000', $report['paid_now']);
        $this->assertSame('0.0000', $report['due_total']);
    }

    /** @return array<string, mixed> */
    private function setupSalesReport(): array
    {
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Main Showroom',
            'code' => 'SHOWROOM',
            'type' => 'showroom',
            'is_default' => true,
            'is_active' => true,
        ]);
        $otherWarehouse = Warehouse::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Second Showroom',
            'code' => 'SHOWROOM-2',
            'type' => 'showroom',
            'is_default' => false,
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'code' => 'CUS-MONTH',
            'name' => 'Monthly Sales Customer',
            'is_active' => true,
        ]);
        $unit = Unit::query()->create([
            'name' => 'Piece',
            'code' => 'PCS',
            'precision' => 0,
            'is_active' => true,
        ]);
        $category = FinishedProductCategory::query()->create([
            'code' => 'FINISHED-SALES',
            'name' => 'Sales Products',
            'is_active' => true,
        ]);
        $product = FinishedProduct::query()->create([
            'product_code' => 'FG-SALE-MONTH',
            'name' => 'Monthly Sales Product',
            'finished_product_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'retail_price' => '500.0000',
            'is_active' => true,
        ]);
        $period = ReportingPeriod::query()->create([
            'year' => 2026,
            'month' => 11,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'status' => 'OPEN',
        ]);
        $paymentTypeId = DB::table('payment_types')->insertGetId([
            'name' => 'Cash',
            'slug' => 'cash',
            'allows_change' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'branch' => $branch,
            'warehouse' => $warehouse,
            'other_warehouse' => $otherWarehouse,
            'customer' => $customer,
            'unit' => $unit,
            'product' => $product,
            'period' => $period,
            'payment_type_id' => $paymentTypeId,
        ];
    }

    /** @param array<string, mixed> $setup */
    private function createSale(
        array $setup,
        int $warehouseId,
        string $saleNo,
        string $saleAt,
        string $status,
        string $total,
        string $paidAmount,
        string $dueAmount,
    ): int {
        return DB::table('sales')->insertGetId([
            'sale_no' => $saleNo,
            'branch_id' => $setup['branch']->id,
            'warehouse_id' => $warehouseId,
            'customer_id' => $setup['customer']->id,
            'channel' => 'POS',
            'sale_at' => $saleAt,
            'status' => $status,
            'subtotal' => $total,
            'discount_type' => 'FLAT',
            'discount_value' => '0.0000',
            'discount' => '0.0000',
            'tax' => '0.0000',
            'total' => $total,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPayment(
        int $saleId,
        int $paymentTypeId,
        ?int $receiptId,
        string $paymentNo,
        string $paymentAt,
        string $amount,
    ): void {
        DB::table('sale_payments')->insert([
            'payment_no' => $paymentNo,
            'sale_id' => $saleId,
            'customer_receipt_id' => $receiptId,
            'payment_type_id' => $paymentTypeId,
            'payment_at' => $paymentAt,
            'amount' => $amount,
            'tendered_amount' => $amount,
            'change_amount' => '0.0000',
            'status' => 'POSTED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $setup */
    private function createOtherWarehouseTransactions(array $setup): void
    {
        $saleId = $this->createSale(
            $setup,
            $setup['other_warehouse']->id,
            'SALE-OTHER-WAREHOUSE',
            '2026-11-12 10:00:00',
            'POSTED',
            '300.0000',
            '300.0000',
            '0.0000',
        );
        $this->createPayment(
            $saleId,
            $setup['payment_type_id'],
            null,
            'PAY-OTHER-WAREHOUSE',
            '2026-11-12 10:05:00',
            '300.0000',
        );
        DB::table('customer_receipts')->insert([
            'receipt_no' => 'CR-OTHER-WAREHOUSE',
            'branch_id' => $setup['branch']->id,
            'customer_id' => $setup['customer']->id,
            'sale_id' => $saleId,
            'receipt_at' => '2026-11-12 10:10:00',
            'amount' => '25.0000',
            'due_before' => '25.0000',
            'due_after' => '0.0000',
            'status' => 'POSTED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
