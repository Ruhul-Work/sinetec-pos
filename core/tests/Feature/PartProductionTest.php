<?php

namespace Tests\Feature;

use App\Enums\Finance\FinanceMappingKey;
use App\Enums\Production\PartProductionStatus;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Http\Controllers\backend\PartProductionController;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\FiscalYear;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\PaymentType;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Role;
use App\Models\backend\Unit;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use App\Models\Inventory\RawMaterialStockMovement;
use App\Models\Production\PartBom;
use App\Models\Production\ProductionExpenseCategory;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceAccountMappingService;
use App\Services\Finance\FinanceFoundationService;
use App\Services\Finance\JournalWorkflowService;
use App\Services\Production\PartBomService;
use App\Services\Production\PartProductionService;
use Database\Seeders\ProductionFoundationSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_part_production_consumes_raw_material_and_receives_good_part(): void
    {
        $setup = $this->productionSetup('10');
        $service = app(PartProductionService::class);

        $batch = $service->createDraft(
            $this->header($setup, '2', '2', '0', 'PASSED'),
            [[
                'raw_material_id' => $setup['raw_material']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '2',
            ]],
            [],
            null,
        );

        $completed = $service->complete($batch, null);

        $this->assertSame(PartProductionStatus::Completed, $completed->status);
        $this->assertDatabaseHas('raw_material_stock_balances', [
            'raw_material_id' => $setup['raw_material']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => 8,
        ]);
        $this->assertDatabaseHas('part_stock_balances', [
            'part_id' => $setup['part']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('raw_material_stock_movements', [
            'source_type' => 'part_production_batch',
            'source_id' => $batch->id,
            'movement_type' => 'PART_PRODUCTION_CONSUMPTION',
            'direction' => 'OUT',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('part_stock_movements', [
            'source_type' => 'part_production_batch',
            'source_id' => $batch->id,
            'movement_type' => 'PART_PRODUCTION_OUTPUT',
            'direction' => 'IN',
            'quantity' => 2,
        ]);
    }

    public function test_insufficient_raw_material_rolls_back_the_entire_completion(): void
    {
        $setup = $this->productionSetup('1');
        $service = app(PartProductionService::class);

        $batch = $service->createDraft(
            $this->header($setup, '2', '2', '0', 'PASSED'),
            [[
                'raw_material_id' => $setup['raw_material']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '2',
            ]],
            [],
            null,
        );

        try {
            $service->complete($batch, null);
            $this->fail('Completion should fail when raw material stock is insufficient.');
        } catch (InsufficientStockException) {
            // Expected: the stock engine rejects the OUT posting atomically.
        }

        $this->assertSame(PartProductionStatus::Draft, $batch->fresh()->status);
        $this->assertDatabaseHas('raw_material_stock_balances', [
            'raw_material_id' => $setup['raw_material']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseMissing('part_stock_balances', [
            'part_id' => $setup['part']->id,
            'warehouse_id' => $setup['warehouse']->id,
        ]);
        $this->assertDatabaseMissing('raw_material_stock_movements', [
            'source_type' => 'part_production_batch',
            'source_id' => $batch->id,
        ]);
    }

    public function test_costed_production_creates_a_balanced_draft_journal(): void
    {
        $setup = $this->productionSetup('10');
        app(FinanceFoundationService::class)->bootstrap();
        $this->seed(VoucherTypeSeeder::class);
        $this->seed(ProductionFoundationSeeder::class);

        FiscalYear::query()->create([
            'name' => 'FY '.now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'is_active' => true,
        ]);

        $rawInventory = Account::query()->where('code', '1100')->firstOrFail();
        $partInventory = Account::query()->where('code', '1110')->firstOrFail();
        $productionLoss = Account::query()->where('code', '5100')->firstOrFail();
        app(FinanceAccountMappingService::class)->saveCompanyMappings([
            FinanceMappingKey::RawMaterialInventory->value => $rawInventory->id,
            FinanceMappingKey::PartInventory->value => $partInventory->id,
            FinanceMappingKey::ProductionLoss->value => $productionLoss->id,
        ]);

        RawMaterialStockMovement::query()->create([
            'raw_material_id' => $setup['raw_material']->id,
            'branch_id' => $setup['branch']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'movement_at' => now(),
            'movement_type' => 'OPENING',
            'direction' => 'IN',
            'quantity' => '10.000000',
            'unit_cost' => '5.0000',
            'source_type' => 'test_opening',
            'source_id' => 1,
            'posting_key' => 'test:raw-material-opening:1',
        ]);

        $service = app(PartProductionService::class);
        $batch = $service->createDraft(
            $this->header($setup, '2', '2', '0', 'PASSED'),
            [[
                'raw_material_id' => $setup['raw_material']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '2',
            ]],
            [],
            null,
        );

        $preview = $service->materialCostPreview($batch);

        $this->assertTrue($preview['is_estimate']);
        $this->assertSame('5.0000', $preview['lines'][$batch->consumptions->first()->id]['unit_cost']);
        $this->assertSame('10.0000', $preview['material_cost']);
        $this->assertSame('5.0000', $preview['good_output_unit_cost']);

        $completed = $service->complete($batch, null);

        $this->assertNotNull($completed->journal);
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'part_production_batch',
            'source_id' => $batch->id,
            'status' => 'DRAFT',
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $completed->journal->id,
            'account_id' => $partInventory->id,
            'debit' => 10,
            'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $completed->journal->id,
            'account_id' => $rawInventory->id,
            'debit' => 0,
            'credit' => 10,
        ]);
    }

    public function test_production_journal_posts_independently_to_expense_payable(): void
    {
        $setup = $this->productionSetup('10');
        app(FinanceFoundationService::class)->bootstrap();
        $this->seed(VoucherTypeSeeder::class);
        $this->seed(ProductionFoundationSeeder::class);

        FiscalYear::query()->create([
            'name' => 'FY '.now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'is_active' => true,
        ]);

        $rawInventory = Account::query()->where('code', '1100')->firstOrFail();
        $partInventory = Account::query()->where('code', '1110')->firstOrFail();
        $productionExpensePayable = Account::query()->where('code', '2050')->firstOrFail();
        $productionLoss = Account::query()->where('code', '5100')->firstOrFail();
        app(FinanceAccountMappingService::class)->saveCompanyMappings([
            FinanceMappingKey::RawMaterialInventory->value => $rawInventory->id,
            FinanceMappingKey::PartInventory->value => $partInventory->id,
            FinanceMappingKey::ProductionExpensePayable->value => $productionExpensePayable->id,
            FinanceMappingKey::ProductionLoss->value => $productionLoss->id,
        ]);

        $expenseCategory = ProductionExpenseCategory::query()
            ->where('code', 'DIRECT_LABOUR')
            ->firstOrFail();
        $service = app(PartProductionService::class);
        $batch = $service->createDraft(
            $this->header($setup, '1', '1', '0', 'PASSED'),
            [[
                'raw_material_id' => $setup['raw_material']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '1',
            ]],
            [[
                'production_expense_category_id' => $expenseCategory->id,
                'amount' => '3',
            ]],
            null,
        );
        $batch = $service->complete($batch, null);

        $user = User::query()->create([
            'name' => 'Accountant',
            'email' => 'accountant@example.test',
            'password' => bcrypt('password'),
            'status' => true,
        ]);
        $superRole = Role::query()->create([
            'name' => 'Super Administrator',
            'key' => 'super-administrator',
            'is_super' => true,
        ]);
        $user->update(['role_id' => $superRole->id]);
        $user->load('role');
        $workflow = app(JournalWorkflowService::class);
        $productionJournal = $workflow->approve($batch->journal, $user->id);
        $productionJournal = $workflow->post($productionJournal, $user->id);

        $this->assertSame('POSTED', $productionJournal->status->value);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $productionJournal->id,
            'account_id' => $partInventory->id,
            'debit' => 3,
            'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $productionJournal->id,
            'account_id' => $productionExpensePayable->id,
            'debit' => 0,
            'credit' => 3,
        ]);
    }

    public function test_multiple_production_cost_lines_allow_partial_settlement(): void
    {
        $setup = $this->productionSetup('10');
        app(FinanceFoundationService::class)->bootstrap();
        $this->seed(VoucherTypeSeeder::class);
        $this->seed(ProductionFoundationSeeder::class);

        FiscalYear::query()->create([
            'name' => 'FY '.now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'is_active' => true,
        ]);

        $rawInventory = Account::query()->where('code', '1100')->firstOrFail();
        $partInventory = Account::query()->where('code', '1110')->firstOrFail();
        $productionExpensePayable = Account::query()->where('code', '2050')->firstOrFail();
        $productionLoss = Account::query()->where('code', '5100')->firstOrFail();
        app(FinanceAccountMappingService::class)->saveCompanyMappings([
            FinanceMappingKey::RawMaterialInventory->value => $rawInventory->id,
            FinanceMappingKey::PartInventory->value => $partInventory->id,
            FinanceMappingKey::ProductionExpensePayable->value => $productionExpensePayable->id,
            FinanceMappingKey::ProductionLoss->value => $productionLoss->id,
        ]);

        $labour = ProductionExpenseCategory::query()->where('code', 'DIRECT_LABOUR')->firstOrFail();
        $power = ProductionExpenseCategory::query()->where('code', 'PRODUCTION_POWER')->firstOrFail();
        $production = app(PartProductionService::class);
        $batch = $production->createDraft(
            $this->header($setup, '1', '1', '0', 'PASSED'),
            [[
                'raw_material_id' => $setup['raw_material']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '1',
            ]],
            [
                ['production_expense_category_id' => $labour->id, 'amount' => '3'],
                ['production_expense_category_id' => $power->id, 'amount' => '2.5'],
            ],
            null,
        );
        $batch = $production->complete($batch, null);

        $user = User::query()->create([
            'name' => 'Cashier',
            'email' => 'cashier@example.test',
            'password' => bcrypt('password'),
            'status' => true,
        ]);
        $superRole = Role::query()->create([
            'name' => 'Super Administrator',
            'key' => 'super-administrator',
            'is_super' => true,
        ]);
        $user->update(['role_id' => $superRole->id]);
        $user->load('role');
        $paymentType = PaymentType::query()->create([
            'name' => 'Cash',
            'slug' => 'cash',
            'is_active' => true,
        ]);
        $cashAccount = Account::query()->where('code', '1000')->firstOrFail();
        $cashAccount->update(['allow_negative' => true]);
        $allocations = $batch->expenses->keyBy('production_expense_category_id');

        $workflow = app(JournalWorkflowService::class);
        $productionJournal = $workflow->approve($batch->journal, $user->id);
        $workflow->post($productionJournal, $user->id);

        $settlement = app(ExpenseService::class)->create([
            'branch_id' => $setup['branch']->id,
            'part_production_batch_id' => $batch->id,
            'expense_date' => now()->toDateString(),
            'production_items' => [
                [
                    'selected' => true,
                    'part_production_expense_id' => $allocations[$labour->id]->id,
                    'amount' => '3',
                ],
                [
                    'selected' => true,
                    'part_production_expense_id' => $allocations[$power->id]->id,
                    'amount' => '1',
                ],
            ],
            'payment_type_id' => $paymentType->id,
            'account_id' => $cashAccount->id,
        ], $user->id);

        $this->assertSame('4.0000', $settlement->amount);
        $this->assertCount(2, $settlement->items);

        $recorded = app(ExpenseService::class)->post($settlement, $user->id);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $recorded->journal->id,
            'account_id' => $productionExpensePayable->id,
            'debit' => 4,
            'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $recorded->journal->id,
            'account_id' => $cashAccount->id,
            'debit' => 0,
            'credit' => 4,
        ]);

        $pendingList = $this->partProductionList($user);
        $pendingHtml = implode(' ', $pendingList['aaData'][0]);
        $this->assertStringContainsString('Payment Pending', $pendingHtml);
        $this->assertStringContainsString('Outstanding 5.50', $pendingHtml);
        $this->assertStringContainsString('Settle production costs', $pendingHtml);

        $settlementJournal = $workflow->approve($recorded->journal, $user->id);
        $workflow->post($settlementJournal, $user->id);

        $partiallyPaidList = $this->partProductionList($user);
        $partiallyPaidHtml = implode(' ', $partiallyPaidList['aaData'][0]);
        $this->assertStringContainsString('Partially Paid', $partiallyPaidHtml);
        $this->assertStringContainsString('Paid 4.00 / 5.50', $partiallyPaidHtml);
        $this->assertStringContainsString('Outstanding 1.50', $partiallyPaidHtml);
        $this->assertStringContainsString('Settle production costs', $partiallyPaidHtml);
    }

    /** @return array<string, mixed> */
    private function partProductionList(User $user): array
    {
        $request = Request::create('/production/part-productions/list', 'POST', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'order' => [['column' => 0, 'dir' => 'desc']],
        ]);
        $request->setUserResolver(fn (): User => $user);

        return app(PartProductionController::class)
            ->listAjax($request)
            ->getData(true);
    }

    /** @return array<string, mixed> */
    private function productionSetup(string $openingQuantity): array
    {
        $unit = Unit::query()->create([
            'name' => 'Piece',
            'code' => 'PCS',
            'precision' => 0,
            'is_active' => true,
        ]);
        $rawCategory = RawMaterialCategory::query()->create([
            'code' => 'RAW',
            'name' => 'Raw Materials',
            'is_active' => true,
        ]);
        $partCategory = PartCategory::query()->create([
            'code' => 'PART',
            'name' => 'Parts',
            'is_active' => true,
        ]);
        $rawMaterial = RawMaterial::query()->create([
            'material_code' => 'RM-001',
            'name' => 'Copper Wire',
            'raw_material_category_id' => $rawCategory->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'MATERIAL',
            'is_active' => true,
        ]);
        $part = Part::query()->create([
            'part_code' => 'PT-001',
            'name' => 'Cable Assembly',
            'part_category_id' => $partCategory->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => 'MAKE',
            'is_active' => true,
        ]);
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Production Store',
            'code' => 'PROD',
            'type' => 'production',
            'is_default' => true,
            'is_active' => true,
        ]);

        $bomService = app(PartBomService::class);
        $bom = $bomService->createDraft([
            'part_id' => $part->id,
            'version' => 'V1',
            'output_quantity' => '1',
            'effective_from' => null,
            'effective_to' => null,
        ], [[
            'raw_material_id' => $rawMaterial->id,
            'unit_id' => $unit->id,
            'required_quantity' => '1',
            'wastage_percent' => '0',
        ]], null);
        $bom = $bomService->activate($bom);

        DB::table('raw_material_stock_balances')->insert([
            'raw_material_id' => $rawMaterial->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $openingQuantity,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'unit' => $unit,
            'raw_material' => $rawMaterial,
            'part' => $part,
            'branch' => $branch,
            'warehouse' => $warehouse,
            'bom' => $bom,
        ];
    }

    /** @param array<string, mixed> $setup */
    private function header(
        array $setup,
        string $planned,
        string $good,
        string $reject,
        string $qcStatus,
    ): array {
        /** @var PartBom $bom */
        $bom = $setup['bom'];

        return [
            'part_bom_id' => $bom->id,
            'input_warehouse_id' => $setup['warehouse']->id,
            'output_warehouse_id' => $setup['warehouse']->id,
            'production_date' => now()->toDateString(),
            'planned_quantity' => $planned,
            'good_quantity' => $good,
            'reject_quantity' => $reject,
            'qc_status' => $qcStatus,
            'qc_note' => null,
        ];
    }
}
