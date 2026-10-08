<?php

namespace App\Http\Controllers;

use App\Models\backend\User;
use App\Support\BranchScope;
use Carbon\Carbon;

class CoreController extends Controller
{
    public function home()
    {
        /*
         * The clean manufacturing modules have not been implemented yet.
         * Keep the restored dashboard presentation, but do not read legacy
         * purchase/sale/expense tables. Each module will replace its zero
         * series after its posting workflow is ready.
         */
        $today = Carbon::today();
        $zero = 0.0;

        $todaySales = $zero;
        $grossSales = $zero;
        $totalPurchase = $zero;
        $totalOrders = 0;
        $totalExpense = $zero;
        $netIncome = $zero;
        $totalPurchaseAndExpense = $zero;

        $overallReportData = [
            'today' => [$zero, $zero, $zero, $zero],
            'weekly' => [$zero, $zero, $zero, $zero],
            'monthly' => [$zero, $zero, $zero, $zero],
            'yearly' => [$zero, $zero, $zero, $zero],
        ];

        $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $monthlyIncome = array_fill(0, 12, $zero);
        $monthlyExpense = array_fill(0, 12, $zero);

        $weeklyLabels = [];
        for ($week = 11; $week >= 0; $week--) {
            $weeklyLabels[] = 'W'.$today->copy()->subWeeks($week)->startOfWeek()->weekOfYear;
        }
        $weeklyIncome = array_fill(0, count($weeklyLabels), $zero);
        $weeklyExpense = array_fill(0, count($weeklyLabels), $zero);

        $dailyLabels = [];
        for ($day = 29; $day >= 0; $day--) {
            $dailyLabels[] = $today->copy()->subDays($day)->format('d/m');
        }
        $dailyIncome = array_fill(0, count($dailyLabels), $zero);
        $dailyExpense = array_fill(0, count($dailyLabels), $zero);

        $yearlyLabels = [];
        for ($yearOffset = 4; $yearOffset >= 0; $yearOffset--) {
            $yearlyLabels[] = (string) ($today->year - $yearOffset);
        }
        $yearlyIncome = array_fill(0, count($yearlyLabels), $zero);
        $yearlyExpense = array_fill(0, count($yearlyLabels), $zero);

        $purchaseSalesMetrics = [
            'this_month' => ['purchase' => $zero, 'sales' => $zero],
            'this_week' => ['purchase' => $zero, 'sales' => $zero],
            'this_year' => ['purchase' => $zero, 'sales' => $zero],
        ];

        $weekDayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $monthWeekLabels = ['Week 1', 'Week 2', 'Week 3', 'Week 4'];

        $purchaseSaleChartData = [
            'this_week' => [
                'purchase' => array_fill(0, 7, $zero),
                'sales' => array_fill(0, 7, $zero),
                'labels' => $weekDayLabels,
            ],
            'this_month' => [
                'purchase' => array_fill(0, 4, $zero),
                'sales' => array_fill(0, 4, $zero),
                'labels' => $monthWeekLabels,
            ],
            'this_year' => [
                'purchase' => array_fill(0, 12, $zero),
                'sales' => array_fill(0, 12, $zero),
                'labels' => $monthNames,
            ],
        ];

        // User data belongs to the retained access module and is safe to show.
        $userQuery = User::with('role');
        $branchId = current_branch_id();
        if (! BranchScope::isAll() && $branchId) {
            $userQuery->where('branch_id', $branchId);
        }

        $users = $userQuery->latest()->take(5)->get();

        // These widgets stay empty until their new modules post business data.
        $supplierQuery = collect();
        $customerQuery = collect();
        $recentTransactions = collect();

        return view('backend.modules.dashboard.home', compact(
            'todaySales',
            'grossSales',
            'totalPurchase',
            'totalOrders',
            'totalExpense',
            'netIncome',
            'totalPurchaseAndExpense',
            'monthlyIncome',
            'monthlyExpense',
            'monthNames',
            'weeklyIncome',
            'weeklyExpense',
            'weeklyLabels',
            'dailyIncome',
            'dailyExpense',
            'dailyLabels',
            'yearlyIncome',
            'yearlyExpense',
            'yearlyLabels',
            'users',
            'supplierQuery',
            'customerQuery',
            'overallReportData',
            'purchaseSalesMetrics',
            'purchaseSaleChartData',
            'recentTransactions'
        ));
    }

    public function home2()
    {
        return view('backend.modules.dashboard.home2');
    }
}
