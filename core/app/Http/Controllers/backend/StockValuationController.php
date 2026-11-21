<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\StockValuationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StockValuationController extends Controller
{
    public function __construct(private readonly StockValuationService $valuation) {}

    public function index(Request $request): View
    {
        $fixedBranchId = current_branch_id();
        $branches = DB::table('branches')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when($fixedBranchId, fn (Builder $query, int $branchId) => $query->where('id', $branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $warehouses = DB::table('warehouses as warehouse')
            ->join('branches as branch', 'branch.id', '=', 'warehouse.branch_id')
            ->whereNull('warehouse.deleted_at')
            ->whereNull('branch.deleted_at')
            ->where('warehouse.is_active', true)
            ->where('branch.is_active', true)
            ->when($fixedBranchId, fn (Builder $query, int $branchId) => $query->where('warehouse.branch_id', $branchId))
            ->orderBy('branch.name')
            ->orderBy('warehouse.name')
            ->get(['warehouse.id', 'warehouse.branch_id', 'warehouse.name', 'warehouse.code', 'branch.name as branch_name']);

        $periods = ReportingPeriod::query()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();
        $selectedPeriodId = $request->integer('period_id') ?: ($periods->first()?->id);
        $selectedBranchId = $fixedBranchId ?: ($request->integer('branch_id') ?: null);
        $selectedWarehouseId = $request->integer('warehouse_id') ?: null;
        $selectedDomain = (string) $request->input('domain', '');

        $report = $this->valuation->report(
            $selectedPeriodId,
            $selectedBranchId,
            $selectedWarehouseId,
            $selectedDomain,
        );

        return view('backend.modules.stock_valuation.index', [
            'branches' => $branches,
            'warehouses' => $warehouses,
            'periods' => $periods,
            'selectedPeriodId' => $selectedPeriodId,
            'selectedBranchId' => $selectedBranchId,
            'selectedWarehouseId' => $selectedWarehouseId,
            'selectedDomain' => $selectedDomain,
            'fixedBranchId' => $fixedBranchId,
            'report' => $report,
        ]);
    }
}
