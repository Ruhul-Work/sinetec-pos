@extends('backend.layouts.master')

@section('meta')
    <title>Monthly Stock Report</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Monthly Stock Report</h6>
                <x-backend.page-note title="Report scope" label="View report rules" align="start">
                    Opening is the movement balance before the period. IN and OUT include only movements inside the period.
                    Closing is Opening plus IN minus OUT and represents the period-end position.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Warehouse-wise stock movement summary for the selected reporting month.</p>
        </div>
        <ul class="d-flex align-items-center gap-2 mb-0">
            <li class="fw-medium">
                <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Dashboard
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Monthly Stock Report</li>
        </ul>
    </div>

    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <h5 class="card-title mb-0">Report Filters</h5>
            <button type="button" id="resetMonthlyStockFilters"
                    class="btn btn-outline-secondary btn-sm px-12 py-8 radius-8 app-action-button">
                <iconify-icon icon="mdi:filter-remove-outline"></iconify-icon>
                <span>Reset Filters</span>
            </button>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('reporting.monthly-stock.index') }}" class="row gy-3 align-items-end">
                <div class="col-xl-3 col-md-4">
                    <label for="stockReportPeriod" class="form-label text-sm">Reporting Period</label>
                    <select id="stockReportPeriod" name="period_id" class="form-control form-control-sm js-s2" required>
                        @foreach ($periods as $period)
                            <option value="{{ $period->id }}" @selected((int) $selectedPeriodId === $period->id)>
                                {{ sprintf('%04d-%02d', $period->year, $period->month) }} · {{ $period->status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="stockReportDomain" class="form-label text-sm">Inventory Type</label>
                    <select id="stockReportDomain" name="domain" class="form-control form-control-sm js-s2">
                        <option value="">All types</option>
                        <option value="raw_material" @selected($selectedDomain === 'raw_material')>Raw Material</option>
                        <option value="part" @selected($selectedDomain === 'part')>Part</option>
                        <option value="finished_product" @selected($selectedDomain === 'finished_product')>Finished Product</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="stockReportBranch" class="form-label text-sm">Branch</label>
                    <select id="stockReportBranch" name="branch_id" class="form-control form-control-sm js-s2" @disabled($fixedBranchId)>
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === $branch->id)>
                                {{ $branch->name }} ({{ $branch->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label for="stockReportWarehouse" class="form-label text-sm">Warehouse</label>
                    <select id="stockReportWarehouse" name="warehouse_id" class="form-control form-control-sm js-s2">
                        <option value="">All warehouses</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((int) $selectedWarehouseId === $warehouse->id)>
                                {{ $warehouse->name }} ({{ $warehouse->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-3">
                    <button type="submit" class="btn btn-primary app-primary-action w-100 d-inline-flex align-items-center justify-content-center gap-1 px-14 py-8 radius-8">
                        <iconify-icon icon="mdi:magnify"></iconify-icon>
                        <span>Run Report</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    @php
        $summaryCards = [
            ['label' => 'Opening Stock', 'value' => $report['opening_total'], 'icon' => 'mdi:database-arrow-left-outline', 'variant' => 'opening', 'value_class' => 'text-primary-main'],
            ['label' => 'Stock In', 'value' => $report['in_total'], 'icon' => 'mdi:database-arrow-down-outline', 'variant' => 'debit', 'value_class' => 'text-success-main'],
            ['label' => 'Stock Out', 'value' => $report['out_total'], 'icon' => 'mdi:database-arrow-up-outline', 'variant' => 'credit', 'value_class' => 'text-danger-main'],
            ['label' => 'Closing Stock', 'value' => $report['closing_total'], 'icon' => 'mdi:database-check-outline', 'variant' => 'closing', 'value_class' => 'text-primary-main'],
        ];
    @endphp
    <div class="row g-2 mb-24">
        @foreach ($summaryCards as $card)
            <div class="col-xl-3 col-md-6">
                <div class="card h-100 account-ledger-summary-card account-ledger-summary-card--{{ $card['variant'] }}">
                    <div class="card-body d-flex align-items-center justify-content-between gap-2 py-16">
                        <div>
                            <small class="text-muted d-block mb-6">{{ $card['label'] }}</small>
                            <h6 class="mb-0 fw-bold {{ $card['value_class'] }}">{{ number_format((float) $card['value'], 3) }}</h6>
                        </div>
                        <iconify-icon icon="{{ $card['icon'] }}" class="text-primary-main" style="font-size:28px; line-height:1; flex-shrink:0"></iconify-icon>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">Stock Movement Summary</h5>
            <div class="actions-bar d-flex align-items-center gap-2">
                <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')</ul>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="monthlyStockTable" style="width:100%">
                    <thead>
                        <tr>
                            <th style="width:65px">S.L</th>
                            <th>Inventory</th>
                            <th>Item</th>
                            <th>Location</th>
                            <th class="text-end">Opening</th>
                            <th class="text-end">IN</th>
                            <th class="text-end">OUT</th>
                            <th class="text-end">Closing</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
        var DATATABLE_URL = @json(route('reporting.monthly-stock.list.ajax'));
        S2.auto($('.card-body'));
        $(document).on('preXhr.dt', '#monthlyStockTable', function (event, settings, data) {
            Object.assign(data, {
                period_id: $('#stockReportPeriod').val(),
                domain: $('#stockReportDomain').val(),
                branch_id: $('#stockReportBranch').val(),
                warehouse_id: $('#stockReportWarehouse').val()
            });
        });
        $(document).on('click', '#resetMonthlyStockFilters', function () {
            $('#stockReportDomain').val('').trigger('change');
            $('#stockReportWarehouse').val('').trigger('change');
            @if (! $fixedBranchId)
                $('#stockReportBranch').val('').trigger('change');
            @endif
            window.location = @json(route('reporting.monthly-stock.index'));
        });
    </script>
@endsection
