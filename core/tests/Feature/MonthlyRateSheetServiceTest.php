<?php

namespace Tests\Feature;

use App\Enums\Reporting\MonthlyRateSheetStatus;
use App\Enums\Reporting\ReportingPeriodStatus;
use App\Exceptions\Reporting\InvalidMonthlyRateSheetException;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\backend\User;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlyRateSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MonthlyRateSheetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_approve_and_lock_monthly_rate_sheet(): void
    {
        $this->travelTo('2026-11-21 10:00:00');
        $user = $this->user();
        $period = ReportingPeriod::query()->create([
            'year' => 2026,
            'month' => 11,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'status' => ReportingPeriodStatus::Open,
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
            'material_code' => 'RM-RATE-001',
            'name' => 'Test Resistor',
            'raw_material_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'DIRECT',
            'is_active' => true,
        ]);

        $service = app(MonthlyRateSheetService::class);
        $sheet = $service->create($period, $user->id);
        $this->assertSame(MonthlyRateSheetStatus::Draft, $sheet->status);
        $this->assertSame(1, $sheet->rawMaterialRates()->count());

        $sheet = $service->save($sheet, [
            'raw_material_rates' => [$raw->id => '12.5000'],
            'part_rates' => [],
            'finished_product_rates' => [],
            'note' => 'November opening rate.',
        ], $user->id);
        $this->assertSame('12.5000', $sheet->rawMaterialRates->first()->rate);

        $sheet = $service->approve($sheet, $user->id);
        $this->assertSame(MonthlyRateSheetStatus::Approved, $sheet->status);
        $sheet = $service->lock($sheet, $user->id);
        $this->assertSame(MonthlyRateSheetStatus::Locked, $sheet->status);

        $this->expectException(InvalidMonthlyRateSheetException::class);
        $this->expectExceptionMessage('A Locked Monthly Rate Sheet cannot be changed.');
        $service->save($sheet, [
            'raw_material_rates' => [$raw->id => '13.0000'],
            'part_rates' => [],
            'finished_product_rates' => [],
        ], $user->id);
    }
}
