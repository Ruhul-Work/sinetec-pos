@extends('backend.layouts.master')
@section('meta')
    <title>Dashboard </title>
@endsection
@section('content')
    <div class="dashboard-main-body">

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">

            <h6 class="fw-semibold mb-0 d-flex align-items-center gap-2">
                <span>
                    {{ optional($companySetting)->name ?? config('app.name') }}
                </span>

                <small class=" text-success fw-medium">
                    — POS & Inventory
                </small>
                <span
                    class="badge border text-sm fw-semibold text-success-600 bg-success-100 px-20 py-9 radius-4 text-white">{{ ucwords(Auth::user()?->role->name ?? 'Guest user') }}</span>

            </h6>

            <ul class="d-flex align-items-center gap-2">
                <li class="fw-medium">
                    <a href="index.html" class="d-flex align-items-center gap-1 hover-text-primary">
                        <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                        Dashboard
                    </a>
                </li>
                <li>-</li>
                <li class="fw-medium">POS Software & Inventory</li>
            </ul>
        </div>

        <div class="row gy-4">
            {{-- <div class="col-12">
                <div class="card radius-12">
                    <div class="card-body p-16">
                        <div class="row gy-4">
                            <div class="col-xxl-3 col-xl-4 col-sm-6">
                                <div
                                    class="px-20 py-16 shadow-none radius-8 h-100 gradient-deep-1 left-line line-bg-primary position-relative overflow-hidden">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-8">
                                        <div>
                                            <span class="mb-2 fw-medium text-secondary-light text-md">Gross Sales</span>
                                            <h6 class="fw-semibold mb-1">$40,000</h6>
                                        </div>
                                        <span
                                            class="w-44-px h-44-px radius-8 d-inline-flex justify-content-center align-items-center text-2xl mb-12 bg-primary-100 text-primary-600">
                                            <i class="ri-shopping-cart-fill"></i>
                                        </span>
                                    </div>
                                    <p class="text-sm mb-0"><span
                                            class="bg-success-focus px-1 rounded-2 fw-medium text-success-main text-sm"><i
                                                class="ri-arrow-right-up-line"></i> 80%</span> From last month </p>
                                </div>
                            </div>
                            <div class="col-xxl-3 col-xl-4 col-sm-6">
                                <div
                                    class="px-20 py-16 shadow-none radius-8 h-100 gradient-deep-2 left-line line-bg-lilac position-relative overflow-hidden">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-8">
                                        <div>
                                            <span class="mb-2 fw-medium text-secondary-light text-md">Total Purchase</span>
                                            <h6 class="fw-semibold mb-1">$35,000</h6>
                                        </div>
                                        <span
                                            class="w-44-px h-44-px radius-8 d-inline-flex justify-content-center align-items-center text-2xl mb-12 bg-lilac-200 text-lilac-600">
                                            <i class="ri-handbag-fill"></i>
                                        </span>
                                    </div>
                                    <p class="text-sm mb-0"><span
                                            class="bg-success-focus px-1 rounded-2 fw-medium text-success-main text-sm"><i
                                                class="ri-arrow-right-up-line"></i> 95%</span> From last month </p>
                                </div>
                            </div>
                            <div class="col-xxl-3 col-xl-4 col-sm-6">
                                <div
                                    class="px-20 py-16 shadow-none radius-8 h-100 gradient-deep-3 left-line line-bg-success position-relative overflow-hidden">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-8">
                                        <div>
                                            <span class="mb-2 fw-medium text-secondary-light text-md">Total Income</span>
                                            <h6 class="fw-semibold mb-1">$30,000</h6>
                                        </div>
                                        <span
                                            class="w-44-px h-44-px radius-8 d-inline-flex justify-content-center align-items-center text-2xl mb-12 bg-success-200 text-success-600">
                                            <i class="ri-shopping-cart-fill"></i>
                                        </span>
                                    </div>
                                    <p class="text-sm mb-0"><span
                                            class="bg-danger-focus px-1 rounded-2 fw-medium text-danger-main text-sm"><i
                                                class="ri-arrow-right-down-line"></i> 30%</span> From last month </p>
                                </div>
                            </div>
                            <div class="col-xxl-3 col-xl-4 col-sm-6">
                                <div
                                    class="px-20 py-16 shadow-none radius-8 h-100 gradient-deep-4 left-line line-bg-warning position-relative overflow-hidden">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-8">
                                        <div>
                                            <span class="mb-2 fw-medium text-secondary-light text-md">Total Expense</span>
                                            <h6 class="fw-semibold mb-1">$7,000</h6>
                                        </div>
                                        <span
                                            class="w-44-px h-44-px radius-8 d-inline-flex justify-content-center align-items-center text-2xl mb-12 bg-warning-focus text-warning-600">
                                            <i class="ri-shopping-cart-fill"></i>
                                        </span>
                                    </div>
                                    <p class="text-sm mb-0"><span
                                            class="bg-success-focus px-1 rounded-2 fw-medium text-success-main text-sm"><i
                                                class="ri-arrow-right-up-line"></i> 60%</span> From last month </p>
                                </div>
                            </div>
                            <div class="col-xxl-3 col-xl-4 col-sm-6">
                                <div
                                    class="px-20 py-16 shadow-none radius-8 h-100 gradient-deep-4 left-line line-bg-warning position-relative overflow-hidden">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-8">
                                        <div>
                                            <span class="mb-2 fw-medium text-secondary-light text-md">Total Expense</span>
                                            <h6 class="fw-semibold mb-1">$7,000</h6>
                                        </div>
                                        <span
                                            class="w-44-px h-44-px radius-8 d-inline-flex justify-content-center align-items-center text-2xl mb-12 bg-warning-focus text-warning-600">
                                            <i class="ri-shopping-cart-fill"></i>
                                        </span>
                                    </div>
                                    <p class="text-sm mb-0"><span
                                            class="bg-success-focus px-1 rounded-2 fw-medium text-success-main text-sm"><i
                                                class="ri-arrow-right-up-line"></i> 60%</span> From last month </p>
                                </div>
                            </div>
                            <div class="col-xxl-3 col-xl-4 col-sm-6">
                                <div
                                    class="px-20 py-16 shadow-none radius-8 h-100 gradient-deep-4 left-line line-bg-warning position-relative overflow-hidden">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-8">
                                        <div>
                                            <span class="mb-2 fw-medium text-secondary-light text-md">Total Expense</span>
                                            <h6 class="fw-semibold mb-1">$7,000</h6>
                                        </div>
                                        <span
                                            class="w-44-px h-44-px radius-8 d-inline-flex justify-content-center align-items-center text-2xl mb-12 bg-warning-focus text-warning-600">
                                            <i class="ri-shopping-cart-fill"></i>
                                        </span>
                                    </div>
                                    <p class="text-sm mb-0"><span
                                            class="bg-success-focus px-1 rounded-2 fw-medium text-success-main text-sm"><i
                                                class="ri-arrow-right-up-line"></i> 60%</span> From last month </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div> --}}

            <div class="col-12">
                <div class="card radius-12">
                    <div class="card-body p-16">
                        <div class="row gy-4">

                            <x-backend.dashboard-summary-card
                                label="Today's Sales"
                                description="Posted POS and wholesale invoices"
                                :value="$dashboardSummary['todaySales']"
                                color="success"
                                gradient="3"
                                icon="ri:money-dollar-circle-fill"
                            />

                            <x-backend.dashboard-summary-card
                                label="Monthly Net Sales"
                                description="Invoice totals less sales returns"
                                :value="$dashboardSummary['monthlyNetSales']"
                                color="primary"
                                gradient="1"
                                icon="ri:shopping-cart-2-fill"
                            />

                            <x-backend.dashboard-summary-card
                                label="Monthly Purchase Receipts"
                                description="Posted receipt totals"
                                :value="$dashboardSummary['monthlyPurchases']"
                                color="lilac"
                                gradient="2"
                                icon="ri:handbag-fill"
                            />

                            <x-backend.dashboard-summary-card
                                label="Monthly Sales Invoices"
                                description="Posted POS and wholesale invoice count"
                                :value="$dashboardSummary['monthlyInvoiceCount']"
                                color="primary"
                                gradient="1"
                                icon="ri:file-list-3-fill"
                                :currency="false"
                            />

                            <x-backend.dashboard-summary-card
                                label="Monthly Operating Expenses"
                                description="Posted general expenses"
                                :value="$dashboardSummary['monthlyExpenses']"
                                color="warning"
                                gradient="4"
                                icon="ri:money-dollar-circle-fill"
                            />

                            <x-backend.dashboard-summary-card
                                label="Monthly Sales Returns"
                                description="Posted return totals"
                                :value="$dashboardSummary['monthlySalesReturns']"
                                color="danger"
                                gradient="returns"
                                icon="ri:arrow-go-back-line"
                            />



                        </div>
                    </div>
                </div>
            </div>

            {{--  Income vs Expense Chart --}}
            <div class="col-xxl-8">
                <div class="card h-100">
                    <div class="card-body px-24 pt-24 pb-4 d-flex flex-column">
                        <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between">
                            <h6 class="mb-2 fw-bold text-lg mb-0">Sales vs. Purchase & Expense</h6>
                            <select id="incomeExpenseFilter"
                                class="form-select form-select-sm w-auto bg-base border text-secondary-light radius-8">
                                <option value="monthly">Monthly (this year)</option>
                                <option value="weekly">Weekly (12 weeks)</option>
                                <option value="daily">Daily (30 days)</option>
                                <option value="yearly">Yearly (5 years)</option>
                            </select>
                        </div>
                        <ul class="d-flex flex-wrap align-items-center justify-content-center my-3 gap-24">
                            <li class="d-flex flex-column gap-1">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="w-8-px h-8-px rounded-pill bg-primary-600"></span>
                                    <span class="text-secondary-light text-sm fw-semibold">Net Sales</span>
                                </div>
                                <div class="d-flex align-items-center gap-8">
                                    <h6 class="mb-0" id="chartIncomeValue">৳ {{ number_format($salesPurchaseExpenseTrend['monthly']['totalSales'], 2) }}</h6>
                                </div>
                            </li>
                            <li class="d-flex flex-column gap-1">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="w-8-px h-8-px rounded-pill bg-warning-600"></span>
                                    <span class="text-secondary-light text-sm fw-semibold">Purchase & Expenses </span>
                                </div>
                                <div class="d-flex align-items-center gap-8">
                                    <h6 class="mb-0" id="chartExpenseValue">৳ {{ number_format($salesPurchaseExpenseTrend['monthly']['totalPurchaseAndExpense'], 2) }}</h6>
                                </div>
                            </li>
                        </ul>
                        <div class="dashboard-sales-trend-plot">
                            <div id="incomeExpense" class="apexcharts-tooltip-style-1"></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- overall report show --}}
            <div class="col-xxl-4 col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between">
                            <h6 class="mb-2 fw-bold text-lg">Overall Report</h6>
                            <select id="overallReportFilter"
                                class="form-select form-select-sm w-auto bg-base border text-secondary-light radius-8">
                                <option value="yearly">This Year</option>
                                <option value="monthly" selected>This Month</option>
                                <option value="weekly">This Week</option>
                                <option value="today">Today</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-body p-24">
                        <div class="mt-32">
                            <div id="userOverviewDonutChart" class="mx-auto apexcharts-tooltip-z-none"></div>
                        </div>
                        <div class="row gy-3 mt-24">
                            @foreach ([
                                'purchases' => ['Purchase Receipts', 'primary'],
                                'sales' => ['Posted Sales', 'lilac'],
                                'expenses' => ['Operating Expenses', 'warning'],
                                'returns' => ['Sales Returns', 'danger'],
                            ] as $metric => [$label, $color])
                                <div class="col-6">
                                    <div class="d-flex align-items-baseline gap-8">
                                        <span class="w-8-px h-8-px rounded-pill flex-shrink-0 bg-{{ $color }}-600"></span>
                                        <div>
                                            <div class="text-sm text-secondary-light">{{ $label }}</div>
                                            <strong data-overall-metric="{{ $metric }}">৳ {{ number_format($overallReportData['monthly'][$metric], 2) }}</strong>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- top supplier  show --}}
            <div class="col-xxl-4 col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between">
                            <div>
                                <h6 class="fw-bold text-lg mb-0">Top Suppliers</h6>
                                <span class="text-sm text-secondary-light">This month</span>
                            </div>
                            <a href="{{ route('supplier.index') }}"
                                class="text-primary-600 hover-text-primary d-flex align-items-center gap-1">
                                View All
                                <iconify-icon icon="solar:alt-arrow-right-linear" class="icon"></iconify-icon>
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-24">
                        <div class="table-responsive scroll-sm">
                            <table class="table bordered-table mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">SL</th>
                                        <th scope="col">Name </th>
                                        <th scope="col" class="text-end">Purchase Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($topSuppliers as $item)
                                        <tr>
                                            <td>
                                                <span class="text-secondary-light">{{ $loop->iteration }}</span>
                                            </td>
                                            <td>
                                                <span class="text-secondary-light">{{ $item->name }}</span>
                                            </td>
                                            <td class="text-end">
                                                <span class="text-secondary-light">৳
                                                    {{ number_format($item->total_purchase, 2) }}</span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-center text-secondary-light">No posted purchases this month.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- top customer show --}}

            <div class="col-xxl-4 col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between">
                            <div>
                                <h6 class="fw-bold text-lg mb-0">Top Customers</h6>
                                <span class="text-sm text-secondary-light">This month</span>
                            </div>
                            <a href="{{ route('customer.index') }}"
                                class="text-primary-600 hover-text-primary d-flex align-items-center gap-1">
                                View All
                                <iconify-icon icon="solar:alt-arrow-right-linear" class="icon"></iconify-icon>
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-24">
                        <div class="table-responsive scroll-sm">
                            <table class="table bordered-table mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">SL</th>
                                        <th scope="col">Name </th>
                                        <th scope="col" class="text-end">Net Sales</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($topCustomers as $item)
                                        <tr>
                                            <td>
                                                <span class="text-secondary-light">{{ $loop->iteration }}</span>
                                            </td>
                                            <td>
                                                <span class="text-secondary-light">{{ $item->name }}</span>
                                            </td>
                                            <td class="text-end">
                                                <span class="text-secondary-light">৳
                                                    {{ number_format($item->total_sale, 2) }}</span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-center text-secondary-light">No customer sales with a positive net total this month.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- purchase and sales summary chart --}}
            <div class="col-xxl-4 col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between">
                            <h6 class="mb-2 fw-bold text-lg mb-0">Purchase & Sales</h6>
                            <select id="purchaseSaleFilter"
                                class="form-select form-select-sm w-auto bg-base text-secondary-light">
                                <option value="this_month">This Month</option>
                                <option value="this_week" selected>This Week</option>
                                <option value="this_year">This Year</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-body p-24">
                        <ul class="d-flex flex-wrap align-items-center justify-content-center my-3 gap-3">
                            <li class="d-flex align-items-center gap-2">
                                <span class="w-12-px h-8-px rounded-pill bg-warning-600"></span>
                                <span class="text-secondary-light text-sm fw-semibold">Purchase Receipts: <span
                                        id="purchaseSummaryVal"
                                        class="text-primary-light fw-bold">৳ {{ number_format($purchaseSaleChartData['this_week']['totalPurchase'], 2) }}</span>
                                </span>
                            </li>
                            <li class="d-flex align-items-center gap-2">
                                <span class="w-12-px h-8-px rounded-pill bg-success-600"></span>
                                <span class="text-secondary-light text-sm fw-semibold">Net Sales: <span id="salesSummaryVal"
                                        class="text-primary-light fw-bold">৳ {{ number_format($purchaseSaleChartData['this_week']['totalSales'], 2) }}</span>
                                </span>
                            </li>
                        </ul>
                        <div id="purchaseSaleChart" class="margin-16-minus y-value-left"></div>
                    </div>
                </div>
            </div>

            {{-- USERS SHOW --}}
            <div class="col-xxl-4 col-md-6">
                <div class="card dashboard-activity-card">
                    <div class="card-header border-bottom">
                        <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between">
                            <h6 class="mb-2 fw-bold text-lg mb-0">Users</h6>
                            <a href="{{ route('usermanage.users.index') }}"
                                class="text-primary-600 hover-text-primary d-flex align-items-center gap-1">
                                View All
                                <iconify-icon icon="solar:alt-arrow-right-linear" class="icon"></iconify-icon>
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-20 overflow-auto">
                        <div class="d-flex flex-column gap-12">
                            @forelse($users as $user)
                                <div class="d-flex align-items-center justify-content-between gap-3 dashboard-user-row">
                                    <div class="d-flex align-items-center overflow-hidden">
                                        <div class="w-40-px h-40-px rounded-circle flex-shrink-0 me-12 overflow-hidden d-flex align-items-center justify-content-center bg-primary-100"
                                            style="font-weight: bold; color: #3c50e0; font-size: 16px;">
                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                        </div>
                                        <div class="overflow-hidden">
                                            <h6 class="text-md mb-0 text-truncate" title="{{ $user->name }}">{{ $user->name }}</h6>
                                            <span
                                                class="d-block text-sm text-secondary-light fw-normal text-truncate"
                                                title="{{ $user->role?->name ?? 'User' }}">{{ $user->role?->name ?? 'User' }}</span>
                                        </div>
                                    </div>
                                    @if ($user->status == 1 || $user->status === 'active' || $user->status === true)
                                        <span class="text-success-main fw-medium text-md">Active</span>
                                    @elseif($user->status == 0 || $user->status === 'inactive' || $user->status === false)
                                        <span class="text-danger-main fw-medium text-md">Inactive</span>
                                    @else
                                        <span
                                            class="text-warning-main fw-medium text-md">{{ ucfirst($user->status) }}</span>
                                    @endif
                                </div>
                            @empty
                                <div class="text-center text-secondary-light py-4">
                                    <p>No users found</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            {{-- recent transactions show --}}
            <div class="col-xxl-8">
                <div class="card dashboard-activity-card">
                    <div class="card-header border-bottom">
                        <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between">
                            <h6 class="mb-2 fw-bold text-lg mb-0">Recent Transactions</h6>
                            <span class="text-sm text-secondary-light">Latest 5 posted documents</span>
                        </div>
                    </div>
                    <div class="card-body p-20">
                        <div class="table-responsive scroll-sm dashboard-recent-transactions" tabindex="0" role="region" aria-label="Recent posted transactions">
                            <table class="table bordered-table table-py-8 mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Date</th>
                                        <th scope="col">Reference</th>
                                        <th scope="col">Type</th>
                                        <th scope="col">Branch</th>
                                        <th scope="col" class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($recentTransactions as $transaction)
                                        <tr>
                                            <td class="text-nowrap">{{ $transaction->transaction_at->format('d M Y') }}</td>
                                            <td>
                                                <a class="text-primary-600 fw-medium" href="{{ route($transaction->route, $transaction->id) }}">
                                                    {{ $transaction->reference }}
                                                </a>
                                            </td>
                                            <td><span class="badge bg-info-focus text-info-main">{{ $transaction->label }}</span></td>
                                            <td>{{ $transaction->branch_name ?? '—' }}</td>
                                            <td class="text-end text-nowrap">৳ {{ number_format($transaction->amount, 2) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-secondary-light">No posted transactions available.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection

@section('script')
    <script>
        // ===================== Income VS Expense Start =============================== 
        function createChartTwo(chartId, color1, color2, salesData, purchaseAndExpenseData, periodLabels) {
            // Destroy existing chart if it exists
            if (window.incomeExpenseChart) {
                window.incomeExpenseChart.destroy();
            }

            var options = {
                series: [{
                    name: 'Net Sales',
                    data: salesData
                }, {
                    name: 'Purchase & Expenses',
                    data: purchaseAndExpenseData
                }],
                legend: {
                    show: false
                },
                chart: {
                    type: 'area',
                    width: '100%',
                    height: '100%',
                    parentHeightOffset: 0,
                    toolbar: {
                        show: false
                    },
                    padding: {
                        left: 0,
                        right: 0,
                        top: 0,
                        bottom: 0
                    }
                },
                dataLabels: {
                    enabled: false
                },
                stroke: {
                    curve: 'smooth',
                    width: 3,
                    colors: [color1, color2],
                    lineCap: 'round'
                },
                grid: {
                    show: true,
                    borderColor: '#D1D5DB',
                    strokeDashArray: 1,
                    position: 'back',
                    xaxis: {
                        lines: {
                            show: false
                        }
                    },
                    yaxis: {
                        lines: {
                            show: true
                        }
                    },
                    row: {
                        colors: undefined,
                        opacity: 0.5
                    },
                    column: {
                        colors: undefined,
                        opacity: 0.5
                    },
                    padding: {
                        top: -20,
                        right: 0,
                        bottom: 0,
                        left: 0
                    },
                },
                colors: [color1, color2],
                fill: {
                    type: 'gradient',
                    colors: [color1, color2],
                    gradient: {
                        shade: 'light',
                        type: 'vertical',
                        shadeIntensity: 0.5,
                        gradientToColors: [undefined, `${color2}00`],
                        inverseColors: false,
                        opacityFrom: [0.4, 0.6],
                        opacityTo: [0.3, 0.3],
                        stops: [0, 100],
                    },
                },
                markers: {
                    colors: [color1, color2],
                    strokeWidth: 3,
                    size: 0,
                    hover: {
                        size: 10
                    }
                },
                xaxis: {
                    categories: periodLabels,
                    tooltip: {
                        enabled: false
                    },
                    labels: {
                        formatter: function(value) {
                            return value;
                        },
                        style: {
                            fontSize: "14px"
                        }
                    }
                },
                yaxis: {
                    tickAmount: 8,
                    forceNiceScale: true,
                    max: function(value) {
                        return value > 0 ? value * 1.15 : 1;
                    },
                    labels: {
                        formatter: function(value) {
                            if (Math.abs(value) >= 1000) {
                                return '৳' + (value / 1000).toLocaleString('en-US', { maximumFractionDigits: 1 }) + 'k';
                            }

                            return '৳' + value.toLocaleString('en-US', { maximumFractionDigits: 2 });
                        },
                        style: {
                            fontSize: "14px"
                        }
                    },
                },
                tooltip: {
                    x: {
                        format: 'MMM'
                    },
                    y: {
                        formatter: function(value) {
                            return "৳" + value.toFixed(2);
                        }
                    }
                }
            };

            window.incomeExpenseChart = new ApexCharts(document.querySelector(`#${chartId}`), options);
            window.incomeExpenseChart.render();
        }

        const salesPurchaseExpenseTrend = @json($salesPurchaseExpenseTrend);

        // Function to format currency
        function formatCurrency(value) {
            return '৳ ' + value.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function renderSalesPurchaseExpenseTrend(period) {
            const trend = salesPurchaseExpenseTrend[period];

            createChartTwo('incomeExpense', '#487FFF', '#FF9F29',
                trend.sales, trend.purchaseAndExpense, trend.labels);
            document.getElementById('chartIncomeValue').textContent = formatCurrency(trend.totalSales);
            document.getElementById('chartExpenseValue').textContent = formatCurrency(trend.totalPurchaseAndExpense);
        }

        const trendFilter = document.getElementById('incomeExpenseFilter');
        renderSalesPurchaseExpenseTrend(trendFilter.value);
        trendFilter.addEventListener('change', function(event) {
            renderSalesPurchaseExpenseTrend(event.target.value);
        });
        // ===================== Income VS Expense End =============================== 

        // ================================ Users Overview Donut chart Start ================================ 
        const overallData = @json($overallReportData);
        const overallMetrics = ['purchases', 'sales', 'expenses', 'returns'];

        function overallSeries(period) {
            const values = overallMetrics.map(function (metric) {
                return overallData[period][metric];
            });

            return values.some(value => value > 0) ? values : [];
        }

        var donutOptions = {
            series: overallSeries('monthly'),
            colors: ['#487FFF', '#9935FE', '#FF9F29', '#DC2626'],
            labels: ['Purchase Receipts', 'Posted Sales', 'Operating Expenses', 'Sales Returns'],
            noData: {
                text: 'No posted activity in this period.'
            },
            legend: {
                show: false
            },
            chart: {
                type: 'donut',
                height: 270,
                sparkline: {
                    enabled: true // Remove whitespace
                },
                margin: {
                    top: 0,
                    right: 0,
                    bottom: 0,
                    left: 0
                },
                padding: {
                    top: 0,
                    right: 0,
                    bottom: 0,
                    left: 0
                }
            },
            stroke: {
                width: 0,
            },
            dataLabels: {
                enabled: false
            },
            tooltip: {
                y: {
                    formatter: formatCurrency
                }
            },
            responsive: [{
                breakpoint: 480,
                options: {
                    chart: {
                        width: 200
                    },
                    legend: {
                        position: 'bottom'
                    }
                }
            }],
        };

        var donutChart = new ApexCharts(document.querySelector("#userOverviewDonutChart"), donutOptions);
        donutChart.render();

        document.getElementById('overallReportFilter').addEventListener('change', function(e) {
            const period = e.target.value;
            donutChart.updateSeries(overallSeries(period));
            document.querySelectorAll('[data-overall-metric]').forEach(function (element) {
                element.textContent = formatCurrency(overallData[period][element.dataset.overallMetric]);
            });
        });
        // ================================ Users Overview Donut chart End ================================ 

        const purchaseSaleData = @json($purchaseSaleChartData);

        function purchaseSaleSeries(period) {
            return [
                { name: 'Purchase Receipts', data: period.purchase },
                { name: 'Net Sales', data: period.sales }
            ];
        }

        const purchaseSaleFilter = document.getElementById('purchaseSaleFilter');
        const initialPurchaseSalePeriod = purchaseSaleData[purchaseSaleFilter.value];
        const purchaseSaleChart = new ApexCharts(document.getElementById('purchaseSaleChart'), {
            series: purchaseSaleSeries(initialPurchaseSalePeriod),
            colors: ['#FF9F29', '#45B369'],
            legend: { show: false },
            chart: {
                type: 'bar',
                height: 260,
                toolbar: { show: false }
            },
            grid: { borderColor: '#D1D5DB', strokeDashArray: 4 },
            plotOptions: { bar: { borderRadius: 4, columnWidth: '65%' } },
            dataLabels: { enabled: false },
            xaxis: { categories: initialPurchaseSalePeriod.labels },
            tooltip: { y: { formatter: formatCurrency } }
        });
        purchaseSaleChart.render();

        purchaseSaleFilter.addEventListener('change', function (event) {
            const period = purchaseSaleData[event.target.value];

            document.getElementById('purchaseSummaryVal').textContent = formatCurrency(period.totalPurchase);
            document.getElementById('salesSummaryVal').textContent = formatCurrency(period.totalSales);
            purchaseSaleChart.updateOptions({
                series: purchaseSaleSeries(period),
                xaxis: { categories: period.labels }
            });
        });
         
    </script>


    <style>
        @import url('https://fonts.googleapis.com/css?family=Poppins');

        * {
            font-family: 'Poppins', sans-serif;
        }

        #chart {

            margin: 35px auto;
            opacity: 0.9;
        }

        #timeline-chart .apexcharts-toolbar {
            opacity: 1;
            border: 0;
        }
    </style>
@endsection
