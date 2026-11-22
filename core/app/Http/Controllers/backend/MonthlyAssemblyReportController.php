<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlyAssemblyReportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MonthlyAssemblyReportController extends Controller
{
    public function __construct(private readonly MonthlyAssemblyReportService $reportService) {}

    public function index(Request $request): View
    {
        $fixedBranchId = current_branch_id();
        $branches = DB::table('branches')->whereNull('deleted_at')->where('is_active', true)
            ->when($fixedBranchId, fn (Builder $query, int $branchId) => $query->where('id', $branchId))
            ->orderBy('name')->get(['id', 'name', 'code']);
        $warehouses = DB::table('warehouses as warehouse')
            ->join('branches as branch', 'branch.id', '=', 'warehouse.branch_id')
            ->whereNull('warehouse.deleted_at')->whereNull('branch.deleted_at')
            ->where('warehouse.is_active', true)
            ->when($fixedBranchId, fn (Builder $query, int $branchId) => $query->where('warehouse.branch_id', $branchId))
            ->orderBy('branch.name')->orderBy('warehouse.name')
            ->get(['warehouse.id', 'warehouse.name', 'warehouse.code', 'branch.name as branch_name']);
        $finishedProducts = DB::table('finished_products')->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'product_code']);
        $periods = ReportingPeriod::query()->orderByDesc('year')->orderByDesc('month')->get();

        $selectedPeriodId = $request->integer('period_id') ?: ($periods->first()?->id);
        $selectedBranchId = $fixedBranchId ?: ($request->integer('branch_id') ?: null);
        $selectedWarehouseId = $request->integer('warehouse_id') ?: null;
        $selectedProductId = $request->integer('finished_product_id') ?: null;
        $report = $this->reportService->report($selectedPeriodId, $selectedBranchId, $selectedWarehouseId, $selectedProductId);

        return view('backend.modules.monthly_assembly_report.index', [
            'branches' => $branches,
            'warehouses' => $warehouses,
            'finishedProducts' => $finishedProducts,
            'periods' => $periods,
            'selectedPeriodId' => $selectedPeriodId,
            'selectedBranchId' => $selectedBranchId,
            'selectedWarehouseId' => $selectedWarehouseId,
            'selectedProductId' => $selectedProductId,
            'fixedBranchId' => $fixedBranchId,
            'report' => $report,
        ]);
    }

    public function listAjax(Request $request): JsonResponse
    {
        $report = $this->reportService->report(
            $request->integer('period_id') ?: null,
            current_branch_id() ?: ($request->integer('branch_id') ?: null),
            $request->integer('warehouse_id') ?: null,
            $request->integer('finished_product_id') ?: null,
        );

        $rows = $report['rows']->values()->map(function (object $row, int $index): array {
            $format = fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
            $qcClass = match ($row->qc_status) {
                'PASSED' => 'text-success-main',
                'FAILED' => 'text-danger-main',
                'PARTIAL' => 'text-warning-main',
                default => 'text-muted',
            };
            $component = (float) ($row->component_cost ?? 0);
            $direct = (float) ($row->direct_cost ?? 0);

            return [
                $index + 1,
                '<strong class="text-primary-600">'.e($row->batch_no).'</strong><br><small class="text-muted">'.e(date('d M Y', strtotime($row->assembly_date))).'</small>',
                '<strong>'.e($row->finished_product_name).'</strong><br><code>'.e($row->product_code).'</code> · v'.e($row->bom_version),
                '<strong>'.e($row->output_warehouse_name).'</strong> <code>'.e($row->output_warehouse_code).'</code><br><small class="text-muted">'.e($row->branch_name).' ('.e($row->branch_code).')</small>',
                '<span class="fw-semibold">'.$format($row->planned_quantity).'</span>',
                '<span class="fw-semibold text-success-main">'.$format($row->good_quantity).'</span>',
                '<span class="fw-semibold text-danger-main">'.$format($row->reject_quantity).'</span>',
                '<span class="fw-semibold '.$qcClass.'">'.e(str_replace('_', ' ', ucwords(strtolower($row->qc_status)))).'</span>',
                '<span class="d-block text-end">'.e(number_format($component, 2)).'</span>',
                '<span class="d-block text-end">'.e(number_format($direct, 2)).'</span>',
                '<span class="d-block text-end fw-bold text-primary-main">'.e(number_format($component + $direct, 2)).'</span>',
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'iTotalRecords' => $rows->count(),
            'iTotalDisplayRecords' => $rows->count(),
            'aaData' => $rows->all(),
        ]);
    }
}
