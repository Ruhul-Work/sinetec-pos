<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlyPurchaseReportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MonthlyPurchaseReportController extends Controller
{
    public function __construct(private readonly MonthlyPurchaseReportService $reportService) {}

    public function index(Request $request): View
    {
        $fixedBranchId = current_branch_id();
        $branches = DB::table('branches')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when($fixedBranchId, fn (Builder $query, int $branchId) => $query->where('id', $branchId))
            ->orderBy('name')->get(['id', 'name', 'code']);
        $warehouses = DB::table('warehouses as warehouse')
            ->join('branches as branch', 'branch.id', '=', 'warehouse.branch_id')
            ->whereNull('warehouse.deleted_at')
            ->whereNull('branch.deleted_at')
            ->where('warehouse.is_active', true)
            ->when($fixedBranchId, fn (Builder $query, int $branchId) => $query->where('warehouse.branch_id', $branchId))
            ->orderBy('branch.name')->orderBy('warehouse.name')
            ->get(['warehouse.id', 'warehouse.branch_id', 'warehouse.name', 'warehouse.code', 'branch.name as branch_name']);
        $suppliers = DB::table('suppliers')
            ->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'code']);
        $periods = ReportingPeriod::query()->orderByDesc('year')->orderByDesc('month')->get();

        $selectedPeriodId = $request->integer('period_id') ?: ($periods->first()?->id);
        $selectedBranchId = $fixedBranchId ?: ($request->integer('branch_id') ?: null);
        $selectedWarehouseId = $request->integer('warehouse_id') ?: null;
        $selectedSupplierId = $request->integer('supplier_id') ?: null;
        $selectedDomain = (string) $request->input('domain', '');

        $report = $this->reportService->report(
            $selectedPeriodId,
            $selectedBranchId,
            $selectedWarehouseId,
            $selectedSupplierId,
            $selectedDomain,
        );

        return view('backend.modules.monthly_purchase_report.index', [
            'branches' => $branches,
            'warehouses' => $warehouses,
            'suppliers' => $suppliers,
            'periods' => $periods,
            'selectedPeriodId' => $selectedPeriodId,
            'selectedBranchId' => $selectedBranchId,
            'selectedWarehouseId' => $selectedWarehouseId,
            'selectedSupplierId' => $selectedSupplierId,
            'selectedDomain' => $selectedDomain,
            'fixedBranchId' => $fixedBranchId,
            'report' => $report,
        ]);
    }

    public function linesAjax(Request $request): JsonResponse
    {
        $report = $this->requestReport($request);
        $rows = $report['lines']->values()->map(function (object $line, int $index): array {
            return [
                $index + 1,
                '<span class="badge bg-light text-dark">'.e($line->domain === 'PART' ? 'Part' : 'Raw Material').'</span>',
                '<strong>'.e($line->receipt_no).'</strong><br><small class="text-muted">'.e(date('d M Y', strtotime($line->receipt_date))).'</small>',
                '<strong>'.e($line->item_name).'</strong><br><code>'.e($line->item_code).'</code>',
                '<span class="fw-semibold">'.e(number_format((float) $line->base_quantity, 6)).' '.e($line->unit_code).'</span>',
                '<span class="fw-semibold">'.e(number_format((float) $line->unit_rate, 2)).'</span>',
                '<span class="fw-bold text-primary-main">'.e(number_format((float) $line->line_total, 2)).'</span>',
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'iTotalRecords' => $rows->count(),
            'iTotalDisplayRecords' => $rows->count(),
            'aaData' => $rows->all(),
        ]);
    }

    public function returnsAjax(Request $request): JsonResponse
    {
        $report = $this->requestReport($request);
        $rows = $report['returns']->values()->map(function (object $return, int $index): array {
            return [
                $index + 1,
                '<strong>'.e($return->return_no).'</strong>',
                e(date('d M Y', strtotime($return->return_date))),
                e($return->supplier_name),
                '<span class="fw-bold text-danger-main">'.e(number_format((float) $return->total, 2)).'</span>',
            ];
        });
        return $this->tableResponse($request, $rows);
    }

    public function paymentsAjax(Request $request): JsonResponse
    {
        $report = $this->requestReport($request);
        $rows = $report['payments']->values()->map(function (object $payment, int $index): array {
            return [
                $index + 1,
                '<strong>'.e($payment->payment_no).'</strong>',
                e(date('d M Y', strtotime($payment->payment_date))),
                e($payment->supplier_name),
                '<span class="fw-bold text-success-main">'.e(number_format((float) $payment->amount, 2)).'</span>',
            ];
        });
        return $this->tableResponse($request, $rows);
    }

    private function requestReport(Request $request): array
    {
        $periodId = $request->integer('period_id') ?: null;
        $branchId = current_branch_id() ?: ($request->integer('branch_id') ?: null);
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $supplierId = $request->integer('supplier_id') ?: null;
        $domain = (string) $request->input('domain', '');

        return $this->reportService->report($periodId, $branchId, $warehouseId, $supplierId, $domain);
    }

    private function tableResponse(Request $request, $rows): JsonResponse
    {
        $data = $rows->all();
        return response()->json([
            'draw' => (int) $request->input('draw'),
            'iTotalRecords' => count($data),
            'iTotalDisplayRecords' => count($data),
            'aaData' => $data,
        ]);
    }
}
