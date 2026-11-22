@extends('backend.layouts.master')

@section('meta')
    <title>Monthly Sales Report</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Monthly Sales Report</h6>
                <x-backend.page-note title="Report scope" label="View report rules" align="start">
                    The report includes posted POS and Wholesale Sales, posted Sales Returns and posted Customer Due Receipts
                    inside the selected reporting period. Refund and due-adjustment totals are shown separately.
                    Net Collected means Initial Sale Payments plus Due Collections minus Refunded.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Posted sales, returns and collections for the selected month.</p>
        </div>
        <ul class="d-flex align-items-center gap-2 mb-0">
            <li class="fw-medium"><a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary"><iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>Dashboard</a></li>
            <li>-</li><li class="fw-medium">Monthly Sales Report</li>
        </ul>
    </div>

    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <h5 class="card-title mb-0">Report Filters</h5>
            <button type="button" id="resetMonthlySalesFilters" class="btn btn-outline-secondary btn-sm px-12 py-8 radius-8 app-action-button">
                <iconify-icon icon="mdi:filter-remove-outline"></iconify-icon><span>Reset Filters</span>
            </button>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('reporting.monthly-sales.index') }}" class="row gy-3 align-items-end">
                <div class="col-xl-2 col-md-4">
                    <label for="salesReportPeriod" class="form-label text-sm">Reporting Period</label>
                    <select id="salesReportPeriod" name="period_id" class="form-control form-control-sm js-s2" required>
                        @foreach ($periods as $period)
                            <option value="{{ $period->id }}" @selected((int) $selectedPeriodId === $period->id)>
                                {{ sprintf('%04d-%02d', $period->year, $period->month) }} · {{ $period->status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="salesReportChannel" class="form-label text-sm">Sales Channel</label>
                    <select id="salesReportChannel" name="channel" class="form-control form-control-sm js-s2">
                        <option value="">POS &amp; Wholesale</option>
                        <option value="POS" @selected($selectedChannel === 'POS')>POS</option>
                        <option value="WHOLESALE" @selected($selectedChannel === 'WHOLESALE')>Wholesale</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="salesReportBranch" class="form-label text-sm">Branch</label>
                    <select id="salesReportBranch" name="branch_id" class="form-control form-control-sm js-s2" @disabled($fixedBranchId)>
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === $branch->id)>
                                {{ $branch->name }} ({{ $branch->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="salesReportWarehouse" class="form-label text-sm">Warehouse</label>
                    <select id="salesReportWarehouse" name="warehouse_id" class="form-control form-control-sm js-s2">
                        <option value="">All warehouses</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((int) $selectedWarehouseId === $warehouse->id)>
                                {{ $warehouse->name }} ({{ $warehouse->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="salesReportCustomer" class="form-label text-sm">Customer</label>
                    <select id="salesReportCustomer" name="customer_id" class="form-control form-control-sm js-s2">
                        <option value="">All customers</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected((int) $selectedCustomerId === $customer->id)>
                                {{ $customer->name }}{{ $customer->code ? ' ('.$customer->code.')' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-3">
                    <button type="submit" class="btn btn-primary app-primary-action w-100 d-inline-flex align-items-center justify-content-center gap-1 px-14 py-8 radius-8">
                        <iconify-icon icon="mdi:magnify"></iconify-icon><span>Run Report</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    @php
        $summaryCards = [
            ['label' => 'Gross Sales', 'value' => $report['sale_total'], 'icon' => 'mdi:cart-check', 'variant' => 'opening', 'value_class' => 'text-primary-main'],
            ['label' => 'Sales Returns', 'value' => $report['return_total'], 'icon' => 'mdi:cart-remove', 'variant' => 'credit', 'value_class' => 'text-danger-main'],
            ['label' => 'Net Sales', 'value' => $report['net_sales'], 'icon' => 'mdi:calculator-variant-outline', 'variant' => 'closing', 'value_class' => 'text-primary-main'],
            ['label' => 'Initial Sale Payments', 'value' => $report['initial_payment_total'], 'icon' => 'mdi:cash-register', 'variant' => 'opening', 'value_class' => 'text-primary-main'],
            ['label' => 'Due Collections', 'value' => $report['receipt_total'], 'icon' => 'mdi:cash-check', 'variant' => 'debit', 'value_class' => 'text-success-main'],
            ['label' => 'Refunded', 'value' => $report['refund_total'], 'icon' => 'mdi:cash-refund', 'variant' => 'credit', 'value_class' => 'text-danger-main'],
            ['label' => 'Net Collected', 'value' => $report['net_collected'], 'icon' => 'mdi:cash-multiple', 'variant' => 'debit', 'value_class' => 'text-success-main'],
            ['label' => 'Outstanding Due', 'value' => $report['due_total'], 'icon' => 'mdi:account-cash-outline', 'variant' => 'credit', 'value_class' => 'text-danger-main'],
        ];
    @endphp
    <div class="row g-2 mb-24">
        @foreach ($summaryCards as $card)
            <div class="col-xl-3 col-md-6">
                <div class="card h-100 account-ledger-summary-card account-ledger-summary-card--{{ $card['variant'] }}">
                    <div class="card-body d-flex align-items-center justify-content-between gap-2 py-16">
                        <div>
                            <small class="text-muted d-block mb-6">{{ $card['label'] }}</small>
                            <h6 class="mb-0 fw-bold {{ $card['value_class'] }}">{{ number_format((float) $card['value'], 2) }}</h6>
                        </div>
                        <iconify-icon icon="{{ $card['icon'] }}" class="text-primary-main" style="font-size:28px; line-height:1; flex-shrink:0"></iconify-icon>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">Posted Sales</h5>
            <div class="actions-bar d-flex align-items-center gap-2">
                <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')</ul>
            </div>
        </div>
        <div class="card-body">
            <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="monthlySalesTable" style="width:100%">
                <thead>
                    <tr>
                        <th style="width:65px">S.L</th>
                        <th>Sale</th>
                        <th>Channel</th>
                        <th>Customer</th>
                        <th>Location</th>
                        <th class="text-end">Sale Total</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Due</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <h5 class="card-title mb-0">Sold Item Lines</h5>
        </div>
        <div class="card-body">
            @if ($report['lines']->isEmpty())
                <div class="text-center text-muted py-32">No posted sale item lines found.</div>
            @else
                <div class="table-responsive">
                    <table class="table bordered-table mb-0 align-middle">
                        <thead><tr><th>Sale</th><th>Channel</th><th>Item</th><th class="text-end">Quantity</th><th class="text-end">Unit Price</th><th class="text-end">Line Total</th></tr></thead>
                        <tbody>
                            @foreach ($report['lines'] as $line)
                                <tr>
                                    <td><strong>{{ $line->sale_no }}</strong><br><small class="text-muted">{{ \Illuminate\Support\Carbon::parse($line->sale_at)->format('d M Y') }}</small></td>
                                    <td><span class="badge bg-light text-dark">{{ $line->channel }}</span></td>
                                    <td><strong>{{ $line->product_name }}</strong><br><code>{{ $line->product_code }}</code></td>
                                    <td class="text-end fw-semibold">{{ number_format((float) $line->quantity, 3) }} {{ $line->unit_code }}</td>
                                    <td class="text-end">{{ number_format((float) $line->unit_price, 2) }}</td>
                                    <td class="text-end fw-bold text-primary-main">{{ number_format((float) $line->line_total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0">Posted Sales Returns</h5></div>
                <div class="card-body">
                    @if ($report['returns']->isEmpty())
                        <div class="text-center text-muted py-24">No posted sales returns.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table bordered-table mb-0 align-middle">
                                <thead><tr><th>Return</th><th>Sale</th><th>Customer</th><th class="text-end">Return</th><th class="text-end">Refund</th></tr></thead>
                                <tbody>
                                    @foreach ($report['returns'] as $return)
                                        <tr>
                                            <td><strong>{{ $return->return_no }}</strong><br><small>{{ \Illuminate\Support\Carbon::parse($return->return_at)->format('d M Y') }}</small></td>
                                            <td><strong>{{ $return->sale_no }}</strong><br><span class="badge bg-light text-dark">{{ $return->channel }}</span></td>
                                            <td>{{ $return->customer_name ?: 'Walk-in' }}</td>
                                            <td class="text-end fw-bold text-danger-main">{{ number_format((float) $return->total, 2) }}</td>
                                            <td class="text-end">{{ number_format((float) $return->refund_amount, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0">Customer Collections</h5></div>
                <div class="card-body">
                    @if ($report['receipts']->isEmpty())
                        <div class="text-center text-muted py-24">No posted customer receipts.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table bordered-table mb-0 align-middle">
                                <thead><tr><th>Receipt</th><th>Sale</th><th>Customer</th><th class="text-end">Amount</th></tr></thead>
                                <tbody>
                                    @foreach ($report['receipts'] as $receipt)
                                        <tr>
                                            <td><strong>{{ $receipt->receipt_no }}</strong><br><small>{{ \Illuminate\Support\Carbon::parse($receipt->receipt_at)->format('d M Y') }}</small></td>
                                            <td>{{ $receipt->sale_no }}</td>
                                            <td>{{ $receipt->customer_name ?: 'Walk-in' }}</td>
                                            <td class="text-end fw-bold text-success-main">{{ number_format((float) $receipt->amount, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
        var DATATABLE_URL = @json(route('reporting.monthly-sales.list.ajax'));
        S2.auto($('.card-body'));
        $(document).on('preXhr.dt', '#monthlySalesTable', function (event, settings, data) {
            Object.assign(data, {
                period_id: $('#salesReportPeriod').val(),
                channel: $('#salesReportChannel').val(),
                branch_id: $('#salesReportBranch').val(),
                warehouse_id: $('#salesReportWarehouse').val(),
                customer_id: $('#salesReportCustomer').val()
            });
        });
        $(document).on('click', '#resetMonthlySalesFilters', function () {
            $('#salesReportChannel').val('').trigger('change');
            $('#salesReportWarehouse').val('').trigger('change');
            $('#salesReportCustomer').val('').trigger('change');
            @if (! $fixedBranchId)
                $('#salesReportBranch').val('').trigger('change');
            @endif
            window.location = @json(route('reporting.monthly-sales.index'));
        });
    </script>
@endsection
