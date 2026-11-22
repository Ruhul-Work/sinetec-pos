<?php

namespace Tests\Feature;

use App\Models\backend\Branch;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Models\Production\PartBom;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlyPartProductionReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyPartProductionReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_part_production_report_filters_completed_batches_and_totals(): void
    {
        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $warehouse = Warehouse::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Main Store',
            'code' => 'STORE',
            'type' => 'store',
            'is_default' => true,
            'is_active' => true,
        ]);
        $unit = Unit::query()->create(['name' => 'Piece', 'code' => 'PCS', 'precision' => 0, 'is_active' => true]);
        $category = PartCategory::query()->create([
            'code' => 'PART',
            'name' => 'Parts',
            'is_active' => true,
        ]);
        $rawCategory = RawMaterialCategory::query()->create([
            'code' => 'RM',
            'name' => 'Raw Materials',
            'is_active' => true,
        ]);
        $raw = RawMaterial::query()->create([
            'material_code' => 'RM-PART-PROD-001',
            'name' => 'Production Raw Material',
            'raw_material_category_id' => $rawCategory->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'DIRECT',
            'is_active' => true,
        ]);
        $part = Part::query()->create([
            'part_code' => 'PT-PROD-001',
            'name' => 'Produced Part',
            'part_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => 'MAKE',
            'is_active' => true,
        ]);
        $bom = PartBom::query()->create([
            'part_id' => $part->id,
            'version' => 'V1',
            'output_quantity' => '1',
            'status' => 'ACTIVE',
        ]);
        $period = ReportingPeriod::query()->create([
            'year' => 2026,
            'month' => 11,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'status' => 'OPEN',
        ]);
        $batchId = DB::table('part_production_batches')->insertGetId([
            'batch_no' => 'PP-MONTH-001',
            'part_bom_id' => $bom->id,
            'branch_id' => $branch->id,
            'input_warehouse_id' => $warehouse->id,
            'output_warehouse_id' => $warehouse->id,
            'production_date' => '2026-11-15',
            'planned_quantity' => '10.000000',
            'good_quantity' => '8.000000',
            'reject_quantity' => '2.000000',
            'qc_status' => 'PARTIAL',
            'status' => 'COMPLETED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('part_production_consumptions')->insert([
            'part_production_batch_id' => $batchId,
            'raw_material_id' => $raw->id,
            'unit_id' => $unit->id,
            'quantity' => '1',
            'base_quantity' => '1',
            'unit_cost' => '100',
            'total_cost' => '100',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = app(MonthlyPartProductionReportService::class)->report(
            $period->id,
            $branch->id,
            $warehouse->id,
            $part->id,
        );

        $this->assertCount(1, $report['rows']);
        $this->assertSame('10.000000', $report['planned']);
        $this->assertSame('8.000000', $report['good']);
        $this->assertSame('2.000000', $report['reject']);
        $this->assertSame('100.0000', $report['material_cost']);
    }
}
