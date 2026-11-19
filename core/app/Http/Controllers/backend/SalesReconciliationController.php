<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesReconciliationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SalesReconciliationController extends Controller
{
    public function index(Request $request, SalesReconciliationService $reconciliation): View
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $fixedBranchId = current_branch_id();
        $selectedBranchId = $fixedBranchId ?: (int) ($validated['branch_id'] ?? 0);
        $dateFrom = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();
        $branches = DB::table('branches')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when($fixedBranchId, fn (Builder $query, int $branchId) => $query->where('id', $branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        $report = $reconciliation->reconcile([
            'branch_id' => $selectedBranchId ?: null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        return view('backend.modules.sales_reconciliation.index', compact(
            'branches',
            'dateFrom',
            'dateTo',
            'fixedBranchId',
            'selectedBranchId',
            'report',
        ));
    }
}
