<?php

namespace Tests\Feature;

use App\Enums\Production\PartProductionStatus;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Http\Controllers\backend\PartProductionController;
use App\Models\backend\Branch;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Role;
use App\Models\backend\Unit;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use App\Models\Inventory\RawMaterialStockMovement;
use App\Models\Production\PartBom;
use App\Models\Production\ProductionExpenseCategory;
use App\Services\Production\PartBomService;
use App\Services\Production\PartProductionService;
use Database\Seeders\ProductionFoundationSeeder;
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

    public function test_production_cost_is_retained_for_reporting_without_creating_a_finance_journal(): void
    {
        $setup = $this->productionSetup('10');
        $this->seed(ProductionFoundationSeeder::class);

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

        $labour = ProductionExpenseCategory::query()
            ->where('code', 'DIRECT_LABOUR')
            ->firstOrFail();
        $service = app(PartProductionService::class);
        $batch = $service->createDraft(
            $this->header($setup, '2', '2', '0', 'PASSED'),
            [[
                'raw_material_id' => $setup['raw_material']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '2',
            ]],
            [[
                'production_expense_category_id' => $labour->id,
                'amount' => '3',
                'note' => 'Estimated labour allocation',
            ]],
            null,
        );

        $draftPreview = $service->materialCostPreview($batch);
        $completed = $service->complete($batch, null);
        $completedPreview = $service->materialCostPreview($completed);

        $this->assertTrue($draftPreview['is_estimate']);
        $this->assertSame('10.0000', $draftPreview['material_cost']);
        $this->assertSame('3.0000', $draftPreview['expense_cost']);
        $this->assertSame('6.5000', $draftPreview['good_output_unit_cost']);
        $this->assertFalse($completedPreview['is_estimate']);
        $this->assertSame('13.0000', $completedPreview['total_cost']);
        $this->assertDatabaseHas('part_stock_movements', [
            'source_type' => 'part_production_batch',
            'source_id' => $batch->id,
            'direction' => 'IN',
            'unit_cost' => 6.5,
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'part_production_batch',
            'source_id' => $batch->id,
        ]);
    }

    public function test_production_list_shows_report_only_cost_without_a_settlement_action(): void
    {
        $setup = $this->productionSetup('10');
        $this->seed(ProductionFoundationSeeder::class);
        $labour = ProductionExpenseCategory::query()->where('code', 'DIRECT_LABOUR')->firstOrFail();
        $service = app(PartProductionService::class);
        $batch = $service->createDraft(
            $this->header($setup, '1', '1', '0', 'PASSED'),
            [[
                'raw_material_id' => $setup['raw_material']->id,
                'unit_id' => $setup['unit']->id,
                'quantity' => '1',
            ]],
            [[
                'production_expense_category_id' => $labour->id,
                'amount' => '4',
            ]],
            null,
        );
        $service->complete($batch, null);

        $user = $this->superUser();
        $request = Request::create('/production/part-productions/list', 'POST', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'order' => [['column' => 0, 'dir' => 'desc']],
        ]);
        $request->setUserResolver(fn (): User => $user);

        $response = app(PartProductionController::class)->listAjax($request)->getData(true);
        $html = implode(' ', $response['aaData'][0]);

        $this->assertStringContainsString('Report only', $html);
        $this->assertStringNotContainsString('Settle production costs', $html);
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

        $bom = app(PartBomService::class)->createDraft([
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
        $bom = app(PartBomService::class)->activate($bom);

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

    private function superUser(): User
    {
        $role = Role::query()->create([
            'name' => 'Super Administrator',
            'key' => 'super-administrator',
            'is_super' => true,
        ]);

        return User::query()->create([
            'name' => 'Administrator',
            'email' => 'admin@example.test',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => true,
        ]);
    }
}
