<?php

namespace Tests\Feature;

use App\Enums\Reporting\MonthlyRateSheetStatus;
use App\Enums\Reporting\ReportingPeriodStatus;
use App\Models\backend\Branch;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use App\Models\Inventory\RawMaterialStockBalance;
use App\Models\Reporting\MonthlyRateSheet;
use App\Models\Reporting\MonthlyRawMaterialRate;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\StockValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockValuationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_value_multiplies_current_quantity_by_locked_monthly_rate(): void
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
            'material_code' => 'RM-VAL-001',
            'name' => 'Copper Board',
            'raw_material_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'DIRECT',
            'is_active' => true,
        ]);
        RawMaterialStockBalance::query()->create([
            'raw_material_id' => $raw->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '12',
            'version' => 1,
        ]);
        $period = ReportingPeriod::query()->create([
            'year' => 2026,
            'month' => 11,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'status' => ReportingPeriodStatus::Open,
        ]);
        $sheet = MonthlyRateSheet::query()->create([
            'reporting_period_id' => $period->id,
            'sheet_no' => 'MRS-TEST-001',
            'status' => MonthlyRateSheetStatus::Locked,
        ]);
        MonthlyRawMaterialRate::query()->create([
            'monthly_rate_sheet_id' => $sheet->id,
            'raw_material_id' => $raw->id,
            'rate' => '200.0000',
            'rate_basis' => 'WEIGHTED_PURCHASE',
        ]);

        $report = app(StockValuationService::class)->report($period->id, $branch->id, $warehouse->id);

        $this->assertSame('2400.0000', $report['grand_total']);
        $this->assertCount(1, $report['rows']);
        $this->assertSame('200.0000', $report['rows']->first()['rate']);
        $this->assertSame('2400.0000', $report['rows']->first()['value']);
    }
}
