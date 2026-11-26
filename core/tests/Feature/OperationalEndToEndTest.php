<?php

namespace Tests\Feature;

use App\Enums\Purchase\PurchaseOrderStatus;
use App\Enums\Sales\SaleStatus;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\Customer;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\FiscalYear;
use App\Models\backend\JournalEntry;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\PaymentType;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Supplier;
use App\Models\backend\Unit;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use App\Services\Finance\FinanceAccountMappingService;
use App\Services\Finance\JournalWorkflowService;
use App\Services\Production\PartBomService;
use App\Services\Production\PartProductionService;
use App\Services\Production\ProductAssemblyService;
use App\Services\Production\ProductBomService;
use App\Services\Purchase\PurchaseOrderService;
use App\Services\Purchase\PurchasePaymentService;
use App\Services\Purchase\PurchaseReceiptService;
use App\Services\Purchase\SupplierDueService;
use App\Services\Reporting\DemandPlanService;
use App\Services\Reporting\MaterialRequirementPlanService;
use App\Services\Reporting\MonthlyRateSheetService;
use App\Services\Reporting\MonthlyReportSnapshotService;
use App\Services\Reporting\ReportingPeriodService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\SaleCheckoutService;
use App\Services\Sales\SaleDraftService;
use App\Services\Sales\SaleReturnService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_demand_to_purchase_assembly_sale_return_and_month_close_reconcile(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 25)->setTime(9, 0));
        $date = today()->toDateString();
        $branch = Branch::create(['name' => 'Test Branch', 'code' => 'UAT-BR']);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Test Warehouse',
            'code' => 'UAT-WH',
            'type' => 'store',
            'is_default' => true,
        ]);
        $operator = User::create([
            'branch_id' => $branch->id,
            'name' => 'UAT Operator',
            'email' => 'uat@example.test',
            'password' => bcrypt('test-only-password'),
            'status' => true,
        ]);
        FiscalYear::create([
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
        $this->seed(DatabaseSeeder::class);
        $accounts = Account::query()->pluck('id', 'code');
        app(FinanceAccountMappingService::class)->saveCompanyMappings([
            'RAW_MATERIAL_INVENTORY' => $accounts['1100'],
            'PART_INVENTORY' => $accounts['1110'],
            'INPUT_TAX' => $accounts['1200'],
            'SUPPLIER_PAYABLE' => $accounts['2000'],
            'PURCHASE_DISCOUNT' => $accounts['4100'],
            'CUSTOMER_RECEIVABLE' => $accounts['1210'],
            'OUTPUT_TAX' => $accounts['2200'],
            'SALES_REVENUE' => $accounts['4000'],
        ]);

        $cash = PaymentType::create(['name' => 'Cash', 'slug' => 'cash', 'allows_change' => true, 'is_active' => true]);
        $payment = [
            'payment_type_id' => $cash->id,
            'account_id' => $accounts['1000'],
        ];
        $supplier = Supplier::create(['name' => 'Test Supplier', 'code' => 'UAT-SUP']);
        $customer = Customer::create(['name' => 'Test Customer', 'code' => 'UAT-CUS']);
        $unit = Unit::create(['name' => 'Piece', 'code' => 'PCS', 'precision' => 0, 'is_active' => true]);
        [$material, $part, $product] = $this->createItems($unit);
        DB::table('raw_material_suppliers')->insert([
            'raw_material_id' => $material->id,
            'supplier_id' => $supplier->id,
            'is_preferred' => true,
        ]);

        $recipeHeader = ['version' => 'V1', 'output_quantity' => 1, 'effective_from' => $date, 'effective_to' => null];
        $partRecipes = app(PartBomService::class);
        $partRecipe = $partRecipes->activate($partRecipes->createDraft(
            $recipeHeader + ['part_id' => $part->id],
            [['raw_material_id' => $material->id, 'unit_id' => $unit->id, 'required_quantity' => 1]],
            $operator->id,
        ));
        $productRecipes = app(ProductBomService::class);
        $productRecipe = $productRecipes->activate($productRecipes->createDraft(
            $recipeHeader + ['finished_product_id' => $product->id],
            [
                ['component_type' => 'RAW_MATERIAL', 'component_id' => $material->id, 'unit_id' => $unit->id, 'required_quantity' => 2],
                ['component_type' => 'PART', 'component_id' => $part->id, 'unit_id' => $unit->id, 'required_quantity' => 1],
            ],
            $operator->id,
        ));

        $periods = app(ReportingPeriodService::class);
        $transactionPeriod = $periods->create(2026, 11, $operator->id);
        $planningPeriod = $periods->create(2026, 12, $operator->id);
        $demands = app(DemandPlanService::class);
        $demand = $demands->createDraft($branch, $planningPeriod, 3, [
            $product->id => ['forecast_quantity' => 5, 'safety_quantity' => 0],
        ], null, $operator->id);
        $demand = $demands->approve($demand, $operator->id);
        $plans = app(MaterialRequirementPlanService::class);
        $mrp = $plans->approve($plans->generate($demand, $operator->id), $operator->id);
        $this->assertSame('5.000000', $mrp->finishedProductLines->sole()->net_required);
        $this->assertSame('5.000000', $mrp->componentLines->firstWhere('part_id', $part->id)->make_quantity);
        $this->assertSame('10.000000', $mrp->componentLines->firstWhere('raw_material_id', $material->id)->buy_quantity);

        $purchaseLine = [
            'domain' => 'raw_material',
            'item_id' => $material->id,
            'unit_id' => $unit->id,
            'quantity' => 10,
            'unit_rate' => 25,
        ];
        $purchaseHeader = ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id];
        $orders = app(PurchaseOrderService::class);
        $order = $orders->createDraft($purchaseHeader + [
            'material_requirement_plan_id' => $mrp->id,
            'order_date' => $date,
            'expected_date' => $date,
        ], [$purchaseLine], $operator->id);
        $this->assertSame($mrp->id, $order->material_requirement_plan_id);
        $order = $orders->approve($order, $operator->id);
        $this->assertDatabaseCount('raw_material_stock_movements', 0);

        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->createDraft($purchaseHeader + [
            'purchase_order_id' => $order->id,
            'receipt_date' => $date,
        ], [$purchaseLine + ['purchase_order_item_id' => $order->items->sole()->id]], $operator->id);
        $receipt = $receipts->post($receipt, $operator->id);
        $receipts->post($receipt, $operator->id);
        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
        $this->assertSame('250.0000', $receipt->total);
        $this->assertDatabaseCount('raw_material_stock_movements', 1);
        $this->assertBalance('raw_material', $material->id, $warehouse->id, 10);
        $this->postSourceJournal('purchase_receipt', $receipt->id, $operator->id);

        $batchLocation = ['input_warehouse_id' => $warehouse->id, 'output_warehouse_id' => $warehouse->id];
        $qc = ['reject_quantity' => 0, 'qc_status' => 'PASSED'];
        $production = app(PartProductionService::class);
        $partBatch = $production->createDraft($batchLocation + $qc + [
            'part_bom_id' => $partRecipe->id,
            'production_date' => $date,
            'planned_quantity' => 4,
            'good_quantity' => 4,
        ], [['raw_material_id' => $material->id, 'unit_id' => $unit->id, 'quantity' => 4]], [], $operator->id);
        $production->complete($partBatch, $operator->id);
        $this->assertBalance('raw_material', $material->id, $warehouse->id, 6);
        $this->assertBalance('part', $part->id, $warehouse->id, 4);

        $assembly = app(ProductAssemblyService::class);
        $finishedBatch = $assembly->createDraft($batchLocation + $qc + [
            'product_bom_id' => $productRecipe->id,
            'assembly_date' => $date,
            'planned_quantity' => 2,
            'good_quantity' => 2,
        ], [
            ['component_type' => 'RAW_MATERIAL', 'raw_material_id' => $material->id, 'unit_id' => $unit->id, 'quantity' => 4],
            ['component_type' => 'PART', 'part_id' => $part->id, 'unit_id' => $unit->id, 'quantity' => 2],
        ], [], $operator->id);
        $assembly->complete($finishedBatch, $operator->id);
        $this->assertBalance('raw_material', $material->id, $warehouse->id, 2);
        $this->assertBalance('part', $part->id, $warehouse->id, 2);
        $this->assertBalance('finished_product', $product->id, $warehouse->id, 2);
        $this->assertDatabaseCount('journal_entries', 1);

        $this->travelTo(now()->setTime(12, 0));
        $sale = app(SaleDraftService::class)->createDraft([
            'channel' => 'POS',
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id,
            'sale_at' => "$date 12:00:00",
        ], [['finished_product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => 2]], $operator->id);
        $checkout = app(SaleCheckoutService::class);
        $sale = $checkout->prepare($sale, [$payment + ['amount' => 120]], $operator->id);
        $this->assertSame(SaleStatus::ReadyToPost, $sale->status);
        $this->assertBalance('finished_product', $product->id, $warehouse->id, 2);
        $sale = $checkout->post($sale, $operator->id);
        $this->assertSame('200.0000', $sale->total);
        $this->assertSame('80.0000', $sale->due_amount);
        $this->assertBalance('finished_product', $product->id, $warehouse->id, 0);

        $this->travelTo(now()->setTime(13, 0));
        app(CustomerReceiptService::class)->collect($sale, "$date 13:00:00", [$payment + ['amount' => 80]], null, $operator->id);
        $this->assertSame('0.0000', $sale->fresh()->due_amount);

        $this->travelTo(now()->setTime(13, 15));
        $supplierPayments = app(PurchasePaymentService::class);
        $supplierPayment = $supplierPayments->create($payment + [
            'purchase_receipt_id' => $receipt->id,
            'payment_date' => $date,
            'amount' => 100,
        ], $operator->id);
        $supplierPayments->post($supplierPayment, $operator->id);
        $this->postSourceJournal('purchase_payment', $supplierPayment->id, $operator->id);
        $this->assertSame(150.0, app(SupplierDueService::class)->receiptDue($receipt)['due']);

        $this->travelTo(now()->setTime(14, 0));
        $returns = app(SaleReturnService::class);
        $return = $returns->createDraft([
            'sale_id' => $sale->id,
            'return_at' => $date.'T14:00',
            'reason' => 'UAT return',
        ], [['sale_item_id' => $sale->items->sole()->id, 'quantity' => 1]], $operator->id);
        $return = $returns->post($return, [$payment + ['amount' => 100]], $operator->id);
        $this->assertSame('100.0000', $return->refund_amount);
        $this->assertBalance('finished_product', $product->id, $warehouse->id, 1);
        $this->assertDatabaseCount('journal_entries', 5);
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertSame(5, JournalEntry::query()->where('status', 'POSTED')->count());
        $this->assertSame(730.0, (float) DB::table('journal_entry_lines')->sum('debit'));
        $this->assertSame(730.0, (float) DB::table('journal_entry_lines')->sum('credit'));
        $this->assertSame(0.0, $this->netJournalBalance($accounts['1000']));
        $this->assertSame(-150.0, $this->netJournalBalance($accounts['2000']));

        $rates = app(MonthlyRateSheetService::class);
        $sheet = $rates->create($transactionPeriod, $operator->id);
        $sheet = $rates->save($sheet, [
            'raw_material_rates' => [$material->id => 25],
            'part_rates' => [$part->id => 25],
            'finished_product_rates' => [$product->id => 75],
        ], $operator->id);
        $rates->lock($rates->approve($sheet, $operator->id), $operator->id);
        $this->travelTo(now()->setDate(2026, 12, 1));
        $period = $periods->close($transactionPeriod, 'UAT month-end reconciliation', $operator->id);
        $snapshots = app(MonthlyReportSnapshotService::class);
        $snapshot = $snapshots->generate($period, $operator->id, null, $branch->id);
        $reports = $snapshot->payload['reports'];
        $this->assertCount(6, $reports);
        $this->assertTrue($snapshots->hasValidChecksum($snapshot));
        $this->assertSame(175.0, (float) $reports['stock_valuation']['grand_total']);
        $this->assertSame(250.0, (float) $reports['purchase']['receipt_total']);
        $this->assertSame(100.0, (float) $reports['purchase']['payment_total']);
        $this->assertSame(4.0, (float) $reports['part_production']['good']);
        $this->assertSame(2.0, (float) $reports['assembly']['good']);
        $this->assertSame(100.0, (float) $reports['sales']['net_sales']);
        $this->assertSame(100.0, (float) $reports['sales']['net_collected']);
        $this->assertSame(0.0, (float) $reports['sales']['due_total']);
    }

    private function createItems(Unit $unit): array
    {
        $rawCategory = RawMaterialCategory::create(['name' => 'Test Materials', 'code' => 'UAT-MAT']);
        $partCategory = PartCategory::create(['name' => 'Test Parts', 'code' => 'UAT-PART']);
        $productCategory = FinishedProductCategory::create(['name' => 'Test Products', 'code' => 'UAT-FG']);

        return [
            RawMaterial::create([
                'name' => 'Test Blank',
                'material_code' => 'UAT-RM-01',
                'raw_material_category_id' => $rawCategory->id,
                'base_unit_id' => $unit->id,
                'material_kind' => 'MATERIAL',
            ]),
            Part::create([
                'name' => 'Test Bracket',
                'part_code' => 'UAT-PT-01',
                'part_category_id' => $partCategory->id,
                'base_unit_id' => $unit->id,
                'procurement_mode' => 'MAKE',
            ]),
            FinishedProduct::create([
                'name' => 'Test Control Box',
                'product_code' => 'UAT-FG-01',
                'finished_product_category_id' => $productCategory->id,
                'base_unit_id' => $unit->id,
                'retail_price' => 100,
                'wholesale_price' => 90,
            ]),
        ];
    }

    private function netJournalBalance(int $accountId): float
    {
        return (float) DB::table('journal_entry_lines')
            ->where('account_id', $accountId)
            ->selectRaw('SUM(debit) - SUM(credit) AS balance')
            ->value('balance');
    }

    private function assertBalance(string $domain, int $itemId, int $warehouseId, int $quantity): void
    {
        $this->assertDatabaseHas($domain.'_stock_balances', [
            $domain.'_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'quantity' => $quantity,
        ]);
    }

    private function postSourceJournal(string $sourceType, int $sourceId, int $userId): void
    {
        $journal = JournalEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->sole();
        $workflow = app(JournalWorkflowService::class);
        $this->assertSame('DRAFT', $journal->getRawOriginal('status'));
        $workflow->post($workflow->approve($journal, $userId), $userId);
    }
}
