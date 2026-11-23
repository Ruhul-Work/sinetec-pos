<?php

namespace Tests\Feature;

use App\Models\backend\Branch;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Models\Production\ProductBom;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlyAssemblyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyAssemblyReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_includes_only_completed_assemblies_in_the_selected_period(): void
    {
        $setup = $this->setupAssemblyReport();
        $completedBatchId = $this->createBatch(
            $setup,
            'ASM-MONTH-001',
            '2026-11-15',
            'COMPLETED',
            '10.000000',
            '9.000000',
            '1.000000',
        );

        DB::table('product_assembly_consumptions')->insert([
            'product_assembly_batch_id' => $completedBatchId,
            'raw_material_id' => $setup['raw_material']->id,
            'part_id' => null,
            'unit_id' => $setup['unit']->id,
            'quantity' => '2.000000',
            'base_quantity' => '2.000000',
            'unit_cost' => '62.7500',
            'total_cost' => '125.5000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_assembly_expenses')->insert([
            'product_assembly_batch_id' => $completedBatchId,
            'production_expense_category_id' => $setup['expense_category_id'],
            'amount' => '50.2500',
            'allocation_basis' => 'BATCH',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createBatch(
            $setup,
            'ASM-MONTH-DRAFT',
            '2026-11-20',
            'DRAFT',
            '100.000000',
            '100.000000',
            '0.000000',
        );
        $this->createBatch(
            $setup,
            'ASM-OUTSIDE-PERIOD',
            '2026-12-01',
            'COMPLETED',
            '20.000000',
            '20.000000',
            '0.000000',
        );

        $report = app(MonthlyAssemblyReportService::class)->report(
            $setup['period']->id,
            $setup['branch']->id,
            $setup['warehouse']->id,
            $setup['product']->id,
        );

        $this->assertCount(1, $report['rows']);
        $this->assertSame('ASM-MONTH-001', $report['rows']->first()->batch_no);
        $this->assertSame('10.000000', $report['planned']);
        $this->assertSame('9.000000', $report['good']);
        $this->assertSame('1.000000', $report['reject']);
        $this->assertSame('125.5000', $report['component_cost']);
        $this->assertSame('50.2500', $report['direct_cost']);
        $this->assertSame('175.7500', $report['total_cost']);
    }

    /** @return array<string, mixed> */
    private function setupAssemblyReport(): array
    {
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Assembly Store',
            'code' => 'ASSEMBLY',
            'type' => 'production',
            'is_default' => true,
            'is_active' => true,
        ]);
        $unit = Unit::query()->create([
            'name' => 'Piece',
            'code' => 'PCS',
            'precision' => 0,
            'is_active' => true,
        ]);
        $rawCategory = RawMaterialCategory::query()->create([
            'code' => 'RAW-ASSEMBLY',
            'name' => 'Assembly Materials',
            'is_active' => true,
        ]);
        $rawMaterial = RawMaterial::query()->create([
            'material_code' => 'RM-ASM-001',
            'name' => 'Assembly Wire',
            'raw_material_category_id' => $rawCategory->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'MATERIAL',
            'is_active' => true,
        ]);
        $productCategory = FinishedProductCategory::query()->create([
            'code' => 'FINISHED-ASSEMBLY',
            'name' => 'Finished Assembly Products',
            'is_active' => true,
        ]);
        $product = FinishedProduct::query()->create([
            'product_code' => 'FG-ASM-001',
            'name' => 'Assembly Report Product',
            'finished_product_category_id' => $productCategory->id,
            'base_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $bom = ProductBom::query()->create([
            'finished_product_id' => $product->id,
            'version' => 'V1',
            'output_quantity' => '1.000000',
            'status' => 'ACTIVE',
        ]);
        $period = ReportingPeriod::query()->create([
            'year' => 2026,
            'month' => 11,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'status' => 'OPEN',
        ]);
        $expenseCategoryId = DB::table('production_expense_categories')->insertGetId([
            'code' => 'ASM-LABOUR',
            'name' => 'Assembly Labour',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'branch' => $branch,
            'warehouse' => $warehouse,
            'unit' => $unit,
            'raw_material' => $rawMaterial,
            'product' => $product,
            'bom' => $bom,
            'period' => $period,
            'expense_category_id' => $expenseCategoryId,
        ];
    }

    /** @param array<string, mixed> $setup */
    private function createBatch(
        array $setup,
        string $batchNo,
        string $assemblyDate,
        string $status,
        string $plannedQuantity,
        string $goodQuantity,
        string $rejectQuantity,
    ): int {
        return DB::table('product_assembly_batches')->insertGetId([
            'batch_no' => $batchNo,
            'product_bom_id' => $setup['bom']->id,
            'branch_id' => $setup['branch']->id,
            'input_warehouse_id' => $setup['warehouse']->id,
            'output_warehouse_id' => $setup['warehouse']->id,
            'assembly_date' => $assemblyDate,
            'planned_quantity' => $plannedQuantity,
            'good_quantity' => $goodQuantity,
            'reject_quantity' => $rejectQuantity,
            'qc_status' => $status === 'COMPLETED' ? 'PASSED' : 'PENDING',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
