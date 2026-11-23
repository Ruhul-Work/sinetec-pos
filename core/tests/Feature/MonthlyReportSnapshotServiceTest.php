<?php

namespace Tests\Feature;

use App\Enums\Reporting\MonthlyRateSheetStatus;
use App\Enums\Reporting\MonthlyReportSnapshotScope;
use App\Enums\Reporting\MonthlyReportSnapshotStatus;
use App\Enums\Reporting\ReportingPeriodStatus;
use App\Exceptions\Reporting\InvalidMonthlyReportSnapshotException;
use App\Http\Controllers\backend\MonthlyReportSnapshotController;
use App\Models\backend\Branch;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Role;
use App\Models\backend\Unit;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use App\Models\Inventory\RawMaterialStockBalance;
use App\Models\Reporting\MonthlyRateSheet;
use App\Models\Reporting\MonthlyRawMaterialRate;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlyReportSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MonthlyReportSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_snapshot_freezes_all_report_sections_and_source_changes_cannot_mutate_it(): void
    {
        $this->travelTo('2026-12-05 10:00:00');
        $setup = $this->setupSnapshotFoundation();
        $service = app(MonthlyReportSnapshotService::class);

        $snapshot = $service->generate(
            $setup['period'],
            $setup['user']->id,
            'November final close.',
        );

        $this->assertSame(1, $snapshot->version);
        $this->assertSame(MonthlyReportSnapshotStatus::Current, $snapshot->status);
        $this->assertSame('200.0000', $snapshot->payload['reports']['stock_valuation']['grand_total']);
        $this->assertSame([
            'stock_valuation',
            'purchase',
            'stock',
            'part_production',
            'assembly',
            'sales',
        ], array_keys($snapshot->payload['reports']));
        $this->assertSame(64, strlen((string) $snapshot->checksum));
        $this->assertTrue($service->hasValidChecksum($snapshot));

        $setup['balance']->update(['quantity' => '5.000000']);
        $frozen = $snapshot->fresh();

        $this->assertSame('200.0000', $frozen->payload['reports']['stock_valuation']['grand_total']);
        $this->assertTrue($service->hasValidChecksum($frozen));

        $this->expectException(InvalidMonthlyReportSnapshotException::class);
        $this->expectExceptionMessage('This period already has a Final Snapshot.');
        $service->generate($setup['period'], $setup['user']->id, 'Duplicate attempt.');
    }

    public function test_authorised_reopen_and_reclose_creates_a_new_version_without_overwriting_history(): void
    {
        $this->travelTo('2026-12-05 10:00:00');
        $setup = $this->setupSnapshotFoundation();
        $service = app(MonthlyReportSnapshotService::class);
        $versionOne = $service->generate($setup['period'], $setup['user']->id, 'Initial final snapshot.');

        $this->travel(1)->minute();
        $setup['period']->update([
            'status' => ReportingPeriodStatus::Open,
            'closed_by' => null,
            'closed_at' => null,
        ]);
        $setup['period']->events()->create([
            'action' => 'REOPENED',
            'actor_id' => $setup['user']->id,
            'reason' => 'Correct stock balance.',
        ]);
        $setup['balance']->update(['quantity' => '3.000000']);

        $this->travel(1)->minute();
        $setup['period']->update([
            'status' => ReportingPeriodStatus::Closed,
            'closed_by' => $setup['user']->id,
            'closed_at' => now(),
        ]);
        $setup['period']->events()->create([
            'action' => 'CLOSED',
            'actor_id' => $setup['user']->id,
            'reason' => 'Correction reviewed and period reclosed.',
        ]);

        $versionTwo = $service->generate(
            $setup['period']->fresh(),
            $setup['user']->id,
            'Corrected stock balance after authorised reopen.',
        );

        $this->assertSame(2, $versionTwo->version);
        $this->assertSame($versionOne->id, $versionTwo->supersedes_snapshot_id);
        $this->assertSame('300.0000', $versionTwo->payload['reports']['stock_valuation']['grand_total']);
        $this->assertSame(MonthlyReportSnapshotStatus::Superseded, $versionOne->fresh()->status);
        $this->assertSame('200.0000', $versionOne->fresh()->payload['reports']['stock_valuation']['grand_total']);
        $this->assertDatabaseCount('monthly_report_snapshots', 2);
    }

    public function test_branch_and_warehouse_filters_are_calculated_from_frozen_snapshot_rows(): void
    {
        $setup = $this->setupSnapshotFoundation();
        $otherBranch = Branch::query()->create([
            'name' => 'Second Branch',
            'code' => 'SECOND',
            'is_active' => true,
        ]);
        $otherWarehouse = Warehouse::query()->create([
            'branch_id' => $otherBranch->id,
            'name' => 'Second Store',
            'code' => 'SECOND-STORE',
            'type' => 'store',
            'is_default' => true,
            'is_active' => true,
        ]);
        RawMaterialStockBalance::query()->create([
            'raw_material_id' => $setup['rawMaterial']->id,
            'warehouse_id' => $otherWarehouse->id,
            'quantity' => '3.000000',
            'version' => 1,
        ]);

        $service = app(MonthlyReportSnapshotService::class);
        $snapshot = $service->generate(
            $setup['period'],
            $setup['user']->id,
            'Company-wide snapshot with two branches.',
        );

        $companyReport = $service->presentation($snapshot, null, null);
        $mainBranchReport = $service->presentation(
            $snapshot,
            $setup['branch']->id,
            $setup['warehouse']->id,
        );
        $secondBranchReport = $service->presentation(
            $snapshot,
            $otherBranch->id,
            $otherWarehouse->id,
        );

        $this->assertSame('500.0000', $companyReport['reports']['stock_valuation']['grand_total']);
        $this->assertSame('200.0000', $mainBranchReport['reports']['stock_valuation']['grand_total']);
        $this->assertSame('300.0000', $secondBranchReport['reports']['stock_valuation']['grand_total']);
        $this->assertCount(1, $mainBranchReport['reports']['stock_valuation']['rows']);
        $this->assertCount(1, $secondBranchReport['reports']['stock_valuation']['rows']);
    }

    public function test_snapshot_requires_closed_period_and_locked_rate_sheet(): void
    {
        $setup = $this->setupSnapshotFoundation();
        $setup['period']->update(['status' => ReportingPeriodStatus::Open]);

        $this->expectException(InvalidMonthlyReportSnapshotException::class);
        $this->expectExceptionMessage('only after the reporting period is Closed');

        app(MonthlyReportSnapshotService::class)->generate(
            $setup['period']->fresh(),
            $setup['user']->id,
        );
    }

    public function test_branch_snapshots_freeze_only_the_selected_branch_and_version_independently(): void
    {
        $setup = $this->setupSnapshotFoundation();
        $otherBranch = Branch::query()->create([
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'is_active' => true,
        ]);
        $otherWarehouse = Warehouse::query()->create([
            'branch_id' => $otherBranch->id,
            'name' => 'Other Store',
            'code' => 'OTHER-STORE',
            'type' => 'store',
            'is_default' => true,
            'is_active' => true,
        ]);
        RawMaterialStockBalance::query()->create([
            'raw_material_id' => $setup['rawMaterial']->id,
            'warehouse_id' => $otherWarehouse->id,
            'quantity' => '3.000000',
            'version' => 1,
        ]);

        $service = app(MonthlyReportSnapshotService::class);
        $mainSnapshot = $service->generate(
            $setup['period'],
            $setup['user']->id,
            'Main branch close.',
            $setup['branch']->id,
        );
        $otherSnapshot = $service->generate(
            $setup['period'],
            $setup['user']->id,
            'Other branch close.',
            $otherBranch->id,
        );

        $this->assertSame(MonthlyReportSnapshotScope::Branch, $mainSnapshot->scope_type);
        $this->assertSame($setup['branch']->id, $mainSnapshot->branch_id);
        $this->assertSame('BRANCH:'.$setup['branch']->id, $mainSnapshot->scope_key);
        $this->assertSame(1, $mainSnapshot->version);
        $this->assertSame(1, $otherSnapshot->version);
        $this->assertSame('200.0000', $mainSnapshot->payload['reports']['stock_valuation']['grand_total']);
        $this->assertSame('300.0000', $otherSnapshot->payload['reports']['stock_valuation']['grand_total']);
        $this->assertCount(1, $mainSnapshot->payload['dimensions']['branches']);
        $this->assertCount(1, $mainSnapshot->payload['dimensions']['warehouses']);
        $this->assertDatabaseCount('monthly_report_snapshots', 2);
    }

    public function test_regular_user_cannot_open_another_branch_or_company_snapshot(): void
    {
        $setup = $this->setupSnapshotFoundation();
        $snapshot = app(MonthlyReportSnapshotService::class)->generate(
            $setup['period'],
            $setup['user']->id,
            'Main branch close.',
            $setup['branch']->id,
        );
        $otherBranch = Branch::query()->create([
            'name' => 'Restricted Branch',
            'code' => 'RESTRICTED',
            'is_active' => true,
        ]);
        $role = Role::query()->create([
            'name' => 'Branch Operator',
            'key' => 'branch-operator',
            'is_super' => false,
        ]);
        $operator = User::query()->create([
            'name' => 'Restricted Operator',
            'email' => 'restricted-operator@example.test',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'branch_id' => $otherBranch->id,
        ]);
        $request = Request::create('/reporting/snapshots/'.$snapshot->id, 'GET');
        $request->setUserResolver(fn (): User => $operator);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('You cannot access another branch or company-wide snapshot.');

        app(MonthlyReportSnapshotController::class)
            ->show($snapshot, $request);
    }

    public function test_regular_user_without_an_assigned_branch_cannot_open_snapshot_register(): void
    {
        $role = Role::query()->create([
            'name' => 'Unassigned Operator',
            'key' => 'unassigned-operator',
            'is_super' => false,
        ]);
        $operator = User::query()->create([
            'name' => 'Unassigned Operator',
            'email' => 'unassigned-operator@example.test',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'branch_id' => null,
        ]);
        $request = Request::create('/reporting/snapshots', 'GET');
        $request->setUserResolver(fn (): User => $operator);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Assign a branch to this user before using Monthly Snapshots.');

        app(MonthlyReportSnapshotController::class)
            ->index($request);
    }

    public function test_snapshot_register_ajax_returns_the_existing_datatable_response_shape(): void
    {
        $setup = $this->setupSnapshotFoundation();
        $this->makeUserSuper($setup['user']);
        $request = Request::create('/reporting/snapshots/list', 'POST', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => '2026-11'],
            'order' => [['column' => 0, 'dir' => 'desc']],
        ]);
        $request->setUserResolver(fn (): User => $setup['user']->fresh());

        $response = app(MonthlyReportSnapshotController::class)
            ->listAjax($request);
        $payload = $response->getData(true);

        $this->assertSame(1, $payload['draw']);
        $this->assertSame(1, $payload['recordsTotal']);
        $this->assertSame(1, $payload['recordsFiltered']);
        $this->assertCount(1, $payload['data']);
        $this->assertCount(7, $payload['data'][0]);
    }

    public function test_snapshot_table_ajax_filters_frozen_rows_with_datatable_search(): void
    {
        $setup = $this->setupSnapshotFoundation();
        $this->makeUserSuper($setup['user']);
        $snapshot = app(MonthlyReportSnapshotService::class)->generate(
            $setup['period'],
            $setup['user']->id,
            'Searchable Ajax report package.',
        );
        $presentation = app(MonthlyReportSnapshotService::class)->presentation(
            $snapshot,
            null,
            null,
        );
        $valuationRows = $presentation['reports']['stock_valuation']['rows'];

        $this->assertNotEmpty($valuationRows);

        $request = Request::create(
            '/reporting/snapshots/'.$snapshot->id.'/table',
            'POST',
            [
                'draw' => 1,
                'start' => 0,
                'length' => 10,
                'section' => 'valuation',
                'search' => [
                    'value' => $valuationRows[0]['item_code'],
                ],
            ],
        );
        $request->setUserResolver(fn (): User => $setup['user']->fresh());

        $response = app(MonthlyReportSnapshotController::class)
            ->tableAjax($snapshot, $request)
            ->getData(true);

        $this->assertGreaterThan(0, $response['recordsFiltered']);
        $this->assertNotEmpty($response['data']);
    }

    public function test_all_six_snapshot_tabs_return_ajax_rows_from_the_frozen_payload(): void
    {
        $setup = $this->setupSnapshotFoundation();
        $this->makeUserSuper($setup['user']);
        $snapshot = app(MonthlyReportSnapshotService::class)->generate(
            $setup['period'],
            $setup['user']->id,
            'Ajax report package.',
        );
        $presentation = app(MonthlyReportSnapshotService::class)->presentation(
            $snapshot,
            null,
            null,
        );
        $reports = $presentation['reports'];
        $expectedRowCounts = [
            'valuation' => count($reports['stock_valuation']['rows']),
            'purchase' => count($reports['purchase']['lines']),
            'stock' => count($reports['stock']['rows']),
            'part-production' => count($reports['part_production']['rows']),
            'assembly' => count($reports['assembly']['rows']),
            'sales' => count($reports['sales']['sales']),
        ];

        foreach ($expectedRowCounts as $section => $expectedRowCount) {
            $request = Request::create(
                '/reporting/snapshots/'.$snapshot->id.'/table',
                'POST',
                [
                    'draw' => 1,
                    'start' => 0,
                    'length' => 10,
                    'section' => $section,
                    'search' => ['value' => ''],
                    'order' => [['column' => 0, 'dir' => 'desc']],
                ],
            );
            $request->setUserResolver(fn (): User => $setup['user']->fresh());

            $response = app(MonthlyReportSnapshotController::class)
                ->tableAjax($snapshot, $request);
            $payload = $response->getData(true);

            $this->assertSame(1, $payload['draw'], $section);
            $this->assertArrayHasKey('recordsTotal', $payload, $section);
            $this->assertArrayHasKey('recordsFiltered', $payload, $section);
            $this->assertArrayHasKey('data', $payload, $section);
            $this->assertSame($expectedRowCount, $payload['recordsTotal'], $section);
            $this->assertCount(min($expectedRowCount, 10), $payload['data'], $section);
        }
    }

    private function makeUserSuper(User $user): void
    {
        $role = Role::query()->create([
            'name' => 'Snapshot Super Admin',
            'key' => 'snapshot-super-admin',
            'is_super' => true,
        ]);
        $user->update(['role_id' => $role->id]);
    }

    /** @return array<string, mixed> */
    private function setupSnapshotFoundation(): array
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
            'code' => 'SNAPSHOT-RAW',
            'name' => 'Snapshot Materials',
            'is_active' => true,
        ]);
        $rawMaterial = RawMaterial::query()->create([
            'material_code' => 'RM-SNAPSHOT-001',
            'name' => 'Snapshot Material',
            'raw_material_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'MATERIAL',
            'is_active' => true,
        ]);
        $balance = RawMaterialStockBalance::query()->create([
            'raw_material_id' => $rawMaterial->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '2.000000',
            'version' => 1,
        ]);
        $user = User::query()->create([
            'name' => 'Snapshot User',
            'email' => 'snapshot-user@example.test',
            'password' => Hash::make('password'),
        ]);
        $period = ReportingPeriod::query()->create([
            'year' => 2026,
            'month' => 11,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'status' => ReportingPeriodStatus::Closed,
            'closed_by' => $user->id,
            'closed_at' => now(),
        ]);
        $period->events()->create([
            'action' => 'CLOSED',
            'actor_id' => $user->id,
            'reason' => 'Initial month close.',
        ]);
        $rateSheet = MonthlyRateSheet::query()->create([
            'reporting_period_id' => $period->id,
            'sheet_no' => 'MRS-SNAPSHOT-001',
            'status' => MonthlyRateSheetStatus::Locked,
            'locked_at' => now(),
        ]);
        MonthlyRawMaterialRate::query()->create([
            'monthly_rate_sheet_id' => $rateSheet->id,
            'raw_material_id' => $rawMaterial->id,
            'rate' => '100.0000',
            'rate_basis' => 'MANUAL',
        ]);

        return compact(
            'branch',
            'warehouse',
            'unit',
            'rawMaterial',
            'balance',
            'user',
            'period',
            'rateSheet',
        );
    }
}
