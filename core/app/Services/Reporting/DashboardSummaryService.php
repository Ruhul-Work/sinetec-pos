<?php

namespace App\Services\Reporting;

use App\Enums\Finance\ExpenseStatus;
use App\Enums\Purchase\PurchaseReceiptStatus;
use App\Enums\Purchase\PurchaseReturnStatus;
use App\Enums\Sales\SaleChannel;
use App\Enums\Sales\SaleReturnStatus;
use App\Enums\Sales\SaleStatus;
use App\Models\backend\Branch;
use App\Models\backend\Customer;
use App\Models\backend\Supplier;
use App\Models\backend\User;
use App\Models\Finance\Expense;
use App\Models\Purchase\PurchaseReceipt;
use App\Models\Purchase\PurchaseReturn;
use App\Models\Sales\Sale;
use App\Models\Sales\SaleReturn;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class DashboardSummaryService
{
    public function summarize(?int $branchId): array
    {
        $today = CarbonImmutable::today();
        $tomorrow = $today->addDay();
        $monthStart = $today->startOfMonth();

        $sales = $this->postedInScope(Sale::query(), $branchId, SaleStatus::Posted->value)
            ->where('sale_at', '>=', $monthStart)
            ->where('sale_at', '<', $tomorrow)
            ->selectRaw(
                'COUNT(*) AS invoice_count, SUM(total) AS month_total,
                SUM(CASE WHEN sale_at >= ? THEN total ELSE 0 END) AS today_total',
                [$today->toDateTimeString()],
            )
            ->first();

        $returns = $this->postedInScope(SaleReturn::query(), $branchId, SaleReturnStatus::Posted->value)
            ->where('return_at', '>=', $monthStart)
            ->where('return_at', '<', $tomorrow)
            ->sum('total');

        $purchases = $this->postedInScope(PurchaseReceipt::query(), $branchId, PurchaseReceiptStatus::Posted->value)
            ->whereBetween('receipt_date', [$monthStart->toDateString(), $today->toDateString()])
            ->sum('total');

        $expenses = $this->operatingExpenses($branchId)
            ->whereBetween('expense_date', [$monthStart->toDateString(), $today->toDateString()])
            ->sum('amount');

        return [
            'todaySales' => (float) $sales->today_total,
            'monthlyNetSales' => round((float) $sales->month_total - (float) $returns, 4),
            'monthlyPurchases' => (float) $purchases,
            'monthlyInvoiceCount' => (int) $sales->invoice_count,
            'monthlyExpenses' => (float) $expenses,
            'monthlySalesReturns' => (float) $returns,
        ];
    }

    public function topSuppliers(?int $branchId): Collection
    {
        $today = CarbonImmutable::today();
        $purchaseTotals = $this->postedInScope(PurchaseReceipt::query(), $branchId, PurchaseReceiptStatus::Posted->value)
            ->whereBetween('receipt_date', [$today->startOfMonth()->toDateString(), $today->toDateString()])
            ->selectRaw('supplier_id, SUM(total) AS total_purchase')
            ->groupBy('supplier_id');

        return Supplier::query()
            ->joinSub($purchaseTotals, 'purchases', 'purchases.supplier_id', '=', 'suppliers.id')
            ->select('suppliers.name', 'purchases.total_purchase')
            ->where('purchases.total_purchase', '>', 0)
            ->orderByDesc('purchases.total_purchase')
            ->orderBy('suppliers.id')
            ->limit(5)
            ->get();
    }

    public function topCustomers(?int $branchId): Collection
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth()->toDateTimeString();
        $tomorrow = $today->addDay()->toDateTimeString();

        $sales = $this->postedInScope(Sale::query(), $branchId, SaleStatus::Posted->value)
            ->where('sale_at', '>=', $monthStart)
            ->where('sale_at', '<', $tomorrow)
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, SUM(total) AS amount')
            ->groupBy('customer_id');

        $returns = $this->postedInScope(SaleReturn::query(), $branchId, SaleReturnStatus::Posted->value)
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->where('sale_returns.return_at', '>=', $monthStart)
            ->where('sale_returns.return_at', '<', $tomorrow)
            ->whereNotNull('sales.customer_id')
            ->selectRaw('sales.customer_id, -SUM(sale_returns.total) AS amount')
            ->groupBy('sales.customer_id');

        $activity = $sales->toBase()->unionAll($returns->toBase());

        return Customer::query()
            ->joinSub($activity, 'activity', 'activity.customer_id', '=', 'customers.id')
            ->selectRaw('customers.name, SUM(activity.amount) AS total_sale')
            ->groupBy('customers.id', 'customers.name')
            ->havingRaw('SUM(activity.amount) > 0')
            ->orderByDesc('total_sale')
            ->orderBy('customers.id')
            ->limit(5)
            ->get();
    }

    public function recentTransactions(User $user, ?int $branchId): Collection
    {
        $limit = 5;
        $sales = $this->postedInScope(Sale::query(), $branchId, SaleStatus::Posted->value);
        $sources = [
            ['sales.pos.show', 'POS Sale', (clone $sales)->where('channel', SaleChannel::Pos->value), 'sale_at', 'sale_no', 'total'],
            ['sales.wholesale.show', 'Wholesale Sale', (clone $sales)->where('channel', SaleChannel::Wholesale->value), 'sale_at', 'sale_no', 'total'],
            ['sales.returns.show', 'Sales Return', $this->postedInScope(SaleReturn::query(), $branchId, SaleReturnStatus::Posted->value), 'return_at', 'return_no', 'total'],
            ['purchase.receipts.show', 'Purchase Receipt', $this->postedInScope(PurchaseReceipt::query(), $branchId, PurchaseReceiptStatus::Posted->value), 'receipt_date', 'receipt_no', 'total'],
            ['purchase.returns.show', 'Purchase Return', $this->postedInScope(PurchaseReturn::query(), $branchId, PurchaseReturnStatus::Posted->value), 'return_date', 'return_no', 'total'],
            ['finance.expenses.show', 'Operating Expense', $this->operatingExpenses($branchId), 'expense_date', 'expense_no', 'amount'],
        ];

        $transactions = collect();
        foreach ($sources as [$route, $label, $query, $dateColumn, $referenceColumn, $amountColumn]) {
            if (! $user->canDo($route)) {
                continue;
            }

            $rows = $query->toBase()
                ->select('id', 'branch_id', "{$referenceColumn} as reference", "{$amountColumn} as amount")
                ->selectRaw("COALESCE(posted_at, {$dateColumn}) AS transaction_at")
                ->orderByDesc('transaction_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            foreach ($rows as $row) {
                $row->label = $label;
                $row->route = $route;
                $row->transaction_at = CarbonImmutable::parse($row->transaction_at);
                $transactions->push($row);
            }
        }

        $transactions = $transactions->sortBy([
            ['transaction_at', 'desc'],
            ['id', 'desc'],
            ['route', 'asc'],
        ])->take($limit)->values();

        $branchNames = Branch::query()->withTrashed()
            ->whereIn('id', $transactions->pluck('branch_id')->unique())
            ->pluck('name', 'id');

        foreach ($transactions as $row) {
            $row->branch_name = $branchNames->get($row->branch_id);
        }

        return $transactions;
    }

    public function dashboardCharts(?int $branchId): array
    {
        $today = CarbonImmutable::today();
        $yearStart = $today->startOfYear()->subYears(4);
        $tomorrow = $today->addDay();

        $sales = $this->dailyTotals(
            $this->postedInScope(Sale::query(), $branchId, SaleStatus::Posted->value),
            'sale_at', 'total', $yearStart, $tomorrow,
        );
        $returns = $this->dailyTotals(
            $this->postedInScope(SaleReturn::query(), $branchId, SaleReturnStatus::Posted->value),
            'return_at', 'total', $yearStart, $tomorrow,
        );
        $purchases = $this->dailyTotals(
            $this->postedInScope(PurchaseReceipt::query(), $branchId, PurchaseReceiptStatus::Posted->value),
            'receipt_date', 'total', $yearStart, $tomorrow,
        );
        $expenses = $this->dailyTotals(
            $this->operatingExpenses($branchId),
            'expense_date', 'amount', $yearStart, $tomorrow,
        );

        $daily = [];
        foreach (array_keys($sales + $returns + $purchases + $expenses) as $date) {
            $daily[$date] = [
                'sales' => $sales[$date] ?? 0,
                'returns' => $returns[$date] ?? 0,
                'purchases' => $purchases[$date] ?? 0,
                'expenses' => $expenses[$date] ?? 0,
            ];
        }

        return [
            'salesPurchaseExpenseTrend' => [
                'monthly' => $this->trendBuckets($daily, $today->startOfYear(), 'month', 12),
                'weekly' => $this->trendBuckets($daily, $today->startOfWeek()->subWeeks(11), 'week', 12),
                'daily' => $this->trendBuckets($daily, $today->subDays(29), 'day', 30),
                'yearly' => $this->trendBuckets($daily, $yearStart, 'year', 5),
            ],
            'overallReportData' => [
                'today' => $this->periodTotals($daily, $today, $tomorrow),
                'weekly' => $this->periodTotals($daily, $today->startOfWeek(), $tomorrow),
                'monthly' => $this->periodTotals($daily, $today->startOfMonth(), $tomorrow),
                'yearly' => $this->periodTotals($daily, $today->startOfYear(), $tomorrow),
            ],
            'purchaseSaleChartData' => [
                'this_week' => $this->purchaseSalesBuckets($daily, $today->startOfWeek(), $today->startOfWeek()->addWeek(), 'day'),
                'this_month' => $this->purchaseSalesBuckets($daily, $today->startOfMonth(), $today->startOfMonth()->addMonth(), 'week'),
                'this_year' => $this->purchaseSalesBuckets($daily, $today->startOfYear(), $today->startOfYear()->addYear(), 'month'),
            ],
        ];
    }

    private function purchaseSalesBuckets(array $daily, CarbonImmutable $start, CarbonImmutable $periodEnd, string $unit): array
    {
        $chart = ['labels' => [], 'purchase' => [], 'sales' => []];

        while ($start->lessThan($periodEnd)) {
            $end = $start->addUnit($unit, 1)->min($periodEnd);
            $totals = $this->periodTotals($daily, $start, $end);
            $chart['labels'][] = match ($unit) {
                'day' => $start->format('D'),
                'month' => $start->format('M'),
                'week' => $start->day === $end->subDay()->day
                    ? (string) $start->day
                    : $start->day.'–'.$end->subDay()->day,
            };
            $chart['purchase'][] = $totals['purchases'];
            $chart['sales'][] = round($totals['sales'] - $totals['returns'], 4);
            $start = $end;
        }

        return $chart + [
            'totalPurchase' => round(array_sum($chart['purchase']), 4),
            'totalSales' => round(array_sum($chart['sales']), 4),
        ];
    }

    private function periodTotals(array $daily, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $totals = ['purchases' => 0, 'sales' => 0, 'expenses' => 0, 'returns' => 0];
        for ($day = $start; $day->lessThan($end); $day = $day->addDay()) {
            foreach ($daily[$day->toDateString()] ?? [] as $metric => $amount) {
                $totals[$metric] += $amount;
            }
        }

        return array_map(static fn ($amount): float => round($amount, 4), $totals);
    }

    private function trendBuckets(array $daily, CarbonImmutable $start, string $unit, int $count): array
    {
        $labelFormat = match ($unit) {
            'month' => 'M',
            'year' => 'Y',
            default => 'd/m',
        };
        $trend = ['labels' => [], 'sales' => [], 'purchaseAndExpense' => []];

        for ($index = 0; $index < $count; $index++) {
            $end = $start->addUnit($unit, 1);
            $totals = $this->periodTotals($daily, $start, $end);
            $trend['labels'][] = $start->format($labelFormat);
            $trend['sales'][] = round($totals['sales'] - $totals['returns'], 4);
            $trend['purchaseAndExpense'][] = round($totals['purchases'] + $totals['expenses'], 4);
            $start = $end;
        }

        return $trend + [
            'totalSales' => round(array_sum($trend['sales']), 4),
            'totalPurchaseAndExpense' => round(array_sum($trend['purchaseAndExpense']), 4),
        ];
    }

    private function dailyTotals(
        Builder $query,
        string $dateColumn,
        string $amountColumn,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        return $query
            ->where($dateColumn, '>=', $start->toDateString())
            ->where($dateColumn, '<', $end->toDateString())
            ->selectRaw("DATE({$dateColumn}) AS transaction_date, SUM({$amountColumn}) AS amount")
            ->groupByRaw("DATE({$dateColumn})")
            ->toBase()
            ->pluck('amount', 'transaction_date')
            ->all();
    }

    private function operatingExpenses(?int $branchId): Builder
    {
        return $this->postedInScope(Expense::query(), $branchId, ExpenseStatus::Posted->value)
            ->whereNull('part_production_batch_id');
    }

    private function postedInScope(Builder $query, ?int $branchId, string $status): Builder
    {
        $query->where($query->qualifyColumn('status'), $status);

        if ($branchId !== null) {
            $query->where($query->qualifyColumn('branch_id'), $branchId);
        }

        return $query;
    }
}
