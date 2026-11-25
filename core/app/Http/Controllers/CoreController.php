<?php

namespace App\Http\Controllers;

use App\Models\backend\User;
use App\Services\Reporting\DashboardSummaryService;
use App\Support\BranchScope;
use Illuminate\Http\Request;

class CoreController extends Controller
{
    public function home(Request $request, DashboardSummaryService $summaryService)
    {
        $user = $request->user();
        $branchId = (int) $user->branch_id;
        if ($user->isSuper()) {
            $branchId = BranchScope::isAll() ? null : (BranchScope::currentId() ?? $branchId);
        }
        $dashboardSummary = $summaryService->summarize($branchId);
        $charts = $summaryService->dashboardCharts($branchId);

        $userQuery = User::with('role');
        if ($branchId !== null) {
            $userQuery->where('branch_id', $branchId);
        }

        $users = $userQuery->latest()->take(5)->get();

        $topSuppliers = $summaryService->topSuppliers($branchId);
        $topCustomers = $summaryService->topCustomers($branchId);
        $recentTransactions = $summaryService->recentTransactions($user, $branchId);

        return view('backend.modules.dashboard.home', compact(
            'dashboardSummary',
            'users',
            'topSuppliers',
            'topCustomers',
            'recentTransactions'
        ) + $charts);
    }

    public function home2()
    {
        return view('backend.modules.dashboard.home2');
    }
}
