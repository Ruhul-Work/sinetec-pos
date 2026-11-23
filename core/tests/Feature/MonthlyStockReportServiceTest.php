<?php

namespace Tests\Feature;

use App\Models\backend\Branch;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlyStockReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyStockReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_stock_report_calculates_opening_period_movement_and_closing(): void
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
        $category = RawMaterialCategory::query()->create([
            'code' => 'RM',
            'name' => 'Raw Materials',
            'is_active' => true,
        ]);
        $raw = RawMaterial::query()->create([
            'material_code' => 'RM-STOCK-MONTH',
            'name' => 'Stock Item',
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

        foreach ([[
            'movement_at' => '2026-10-31 10:00:00',
            'direction' => 'IN',
            'quantity' => '10.000000',
            'posting_key' => 'RM-OPEN-TEST',
        ], [
            'movement_at' => '2026-11-10 10:00:00',
            'direction' => 'IN',
            'quantity' => '5.000000',
            'posting_key' => 'RM-IN-TEST',
        ], [
            'movement_at' => '2026-11-20 10:00:00',
            'direction' => 'OUT',
            'quantity' => '2.000000',
            'posting_key' => 'RM-OUT-TEST',
        ]] as $movement) {
            DB::table('raw_material_stock_movements')->insert([
                'raw_material_id' => $raw->id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'movement_at' => $movement['movement_at'],
                'movement_type' => 'ADJUSTMENT',
                'direction' => $movement['direction'],
                'quantity' => $movement['quantity'],
                'unit_cost' => '1.0000',
                'source_type' => 'stock_adjustment',
                'source_id' => 1,
                'posting_key' => $movement['posting_key'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $raw->update(['is_active' => false]);

        $report = app(MonthlyStockReportService::class)->report(
            $period->id,
            $branch->id,
            $warehouse->id,
        );

        $this->assertSame('10.000000', $report['opening_total']);
        $this->assertSame('5.000000', $report['in_total']);
        $this->assertSame('2.000000', $report['out_total']);
        $this->assertSame('13.000000', $report['closing_total']);
        $this->assertCount(1, $report['rows']);
    }
}
