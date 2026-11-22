<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlySalesReportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MonthlySalesReportController extends Controller
{
    public function __construct(private readonly MonthlySalesReportService $reportService) {}

    public function index(Request $request): View
    {
        $fixedBranchId = current_branch_id();
        $branches = DB::table('branches')->whereNull('deleted_at')->where('is_active', true)
            ->when($fixedBranchId, fn (Builder $query, int $branchId) => $query->where('id', $branchId))
            ->orderBy('name')->get(['id', 'name', 'code']);
        $warehouses = DB::table('warehouses as warehouse')
            ->join('branches as branch', 'branch.id', '=', 'warehouse.branch_id')
            ->whereNull('warehouse.deleted_at')->whereNull('branch.deleted_at')->where('warehouse.is_active', true)
            ->when($fixedBranchId, fn (Builder $query, int $branchId) => $query->where('warehouse.branch_id', $branchId))
            ->orderBy('branch.name')->orderBy('warehouse.name')
            ->get(['warehouse.id', 'warehouse.name', 'warehouse.code', 'branch.name as branch_name']);
        $customers = DB::table('customers')->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'code']);
        $periods = ReportingPeriod::query()->orderByDesc('year')->orderByDesc('month')->get();

        $selectedPeriodId = $request->integer('period_id') ?: ($periods->first()?->id);
        $selectedBranchId = $fixedBranchId ?: ($request->integer('branch_id') ?: null);
        $selectedWarehouseId = $request->integer('warehouse_id') ?: null;
        $selectedCustomerId = $request->integer('customer_id') ?: null;
        $selectedChannel = (string) $request->input('channel', '');
        $report = $this->reportService->report(
            $selectedPeriodId,
            $selectedBranchId,
            $selectedWarehouseId,
            $selectedCustomerId,
            $selectedChannel,
        );

        return view('backend.modules.monthly_sales_report.index', [
            'branches' => $branches,
            'warehouses' => $warehouses,
            'customers' => $customers,
            'periods' => $periods,
            'selectedPeriodId' => $selectedPeriodId,
            'selectedBranchId' => $selectedBranchId,
            'selectedWarehouseId' => $selectedWarehouseId,
            'selectedCustomerId' => $selectedCustomerId,
            'selectedChannel' => $selectedChannel,
            'fixedBranchId' => $fixedBranchId,
            'report' => $report,
        ]);
    }

    public function listAjax(Request $request): JsonResponse
    {
        $report = $this->requestReport($request);
        $rows = $report['sales']->values()->map(function (object $sale, int $index): array {
            return [
                $index + 1,
                '<strong class="text-primary-600">'.e($sale->sale_no).'</strong><br><small class="text-muted">'.e(date('d M Y, h:i A', strtotime($sale->sale_at))).'</small>',
                '<span class="badge bg-light text-dark">'.e($sale->channel).'</span>',
                '<strong>'.e($sale->customer_name ?: 'Walk-in').'</strong>',
                '<strong>'.e($sale->warehouse_name).'</strong> <code>'.e($sale->warehouse_code).'</code><br><small class="text-muted">'.e($sale->branch_name).' ('.e($sale->branch_code).')</small>',
                '<span class="d-block text-end fw-semibold">'.e(number_format((float) $sale->total, 2)).'</span>',
                '<span class="d-block text-end fw-semibold text-success-main">'.e(number_format((float) $sale->paid_amount, 2)).'</span>',
                '<span class="d-block text-end fw-semibold text-danger-main">'.e(number_format((float) $sale->due_amount, 2)).'</span>',
            ];
        });
        return $this->tableResponse($request, $rows);
    }

    public function linesAjax(Request $request): JsonResponse
    {
        $report = $this->requestReport($request);
        $rows = $report['lines']->values()->map(function (object $line, int $index): array {
            return [
                $index + 1,
                '<strong>'.e($line->sale_no).'</strong><br><small class="text-muted">'.e(date('d M Y', strtotime($line->sale_at))).'</small>',
                '<span class="badge bg-light text-dark">'.e($line->channel).'</span>',
                '<strong>'.e($line->product_name).'</strong><br><code>'.e($line->product_code).'</code>',
                '<span class="d-block text-end fw-semibold">'.e(number_format((float) $line->quantity, 6).' '.e($line->unit_code)).'</span>',
                '<span class="d-block text-end">'.e(number_format((float) $line->unit_price, 2)).'</span>',
                '<span class="d-block text-end fw-bold text-primary-main">'.e(number_format((float) $line->line_total, 2)).'</span>',
            ];
        });
        return $this->tableResponse($request, $rows);
    }

    public function returnsAjax(Request $request): JsonResponse
    {
        $report = $this->requestReport($request);
        $rows = $report['returns']->values()->map(function (object $return, int $index): array {
            return [
                $index + 1,
                '<strong>'.e($return->return_no).'</strong><br><small class="text-muted">'.e(date('d M Y, h:i A', strtotime($return->return_at))).'</small>',
                '<strong>'.e($return->sale_no).'</strong>',
                '<span class="badge bg-light text-dark">'.e($return->channel).'</span>',
                e($return->customer_name ?: 'Walk-in'),
                '<span class="d-block text-end fw-bold text-danger-main">'.e(number_format((float) $return->total, 2)).'</span>',
                '<span class="d-block text-end">'.e(number_format((float) $return->refund_amount, 2)).'</span>',
            ];
        });
        return $this->tableResponse($request, $rows);
    }

    public function receiptsAjax(Request $request): JsonResponse
    {
        $report = $this->requestReport($request);
        $rows = $report['receipts']->values()->map(function (object $receipt, int $index): array {
            return [
                $index + 1,
                '<strong>'.e($receipt->receipt_no).'</strong><br><small class="text-muted">'.e(date('d M Y, h:i A', strtotime($receipt->receipt_at))).'</small>',
                '<strong>'.e($receipt->sale_no).'</strong>',
                e($receipt->customer_name ?: 'Walk-in'),
                '<span class="d-block text-end fw-bold text-success-main">'.e(number_format((float) $receipt->amount, 2)).'</span>',
            ];
        });
        return $this->tableResponse($request, $rows);
    }

    private function requestReport(Request $request): array
    {
        return $this->reportService->report(
            $request->integer('period_id') ?: null,
            current_branch_id() ?: ($request->integer('branch_id') ?: null),
            $request->integer('warehouse_id') ?: null,
            $request->integer('customer_id') ?: null,
            (string) $request->input('channel', ''),
        );
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
