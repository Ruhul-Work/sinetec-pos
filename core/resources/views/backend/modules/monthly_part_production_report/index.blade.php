@extends('backend.layouts.master')

@section('meta')
    <title>Monthly Part Production Report</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Monthly Part Production Report</h6>
                <x-backend.page-note title="Report scope" label="View report rules" align="start">
                    The report includes completed Part Production batches inside the selected reporting period.
                    Material cost and direct category cost are report-only batch estimates.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Completed internal part production by batch, QC and cost.</p>
        </div>
        <ul class="d-flex align-items-center gap-2 mb-0">
            <li class="fw-medium"><a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary"><iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>Dashboard</a></li>
            <li>-</li><li class="fw-medium">Monthly Part Production</li>
        </ul>
    </div>

    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <h5 class="card-title mb-0">Report Filters</h5>
            <button type="button" id="resetMonthlyPartProductionFilters" class="btn btn-outline-secondary btn-sm px-12 py-8 radius-8 app-action-button">
                <iconify-icon icon="mdi:filter-remove-outline"></iconify-icon><span>Reset Filters</span>
            </button>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('reporting.monthly-part-production.index') }}" class="row gy-3 align-items-end">
                <div class="col-xl-3 col-md-4">
                    <label for="partProductionReportPeriod" class="form-label text-sm">Reporting Period</label>
                    <select id="partProductionReportPeriod" name="period_id" class="form-control form-control-sm js-s2" required>
                        @foreach ($periods as $period)
                            <option value="{{ $period->id }}" @selected((int) $selectedPeriodId === $period->id)>
                                {{ sprintf('%04d-%02d', $period->year, $period->month) }} · {{ $period->status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="partProductionReportBranch" class="form-label text-sm">Branch</label>
                    <select id="partProductionReportBranch" name="branch_id" class="form-control form-control-sm js-s2" @disabled($fixedBranchId)>
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === $branch->id)>{{ $branch->name }} ({{ $branch->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label for="partProductionReportWarehouse" class="form-label text-sm">Warehouse</label>
                    <select id="partProductionReportWarehouse" name="warehouse_id" class="form-control form-control-sm js-s2">
                        <option value="">All warehouses</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((int) $selectedWarehouseId === $warehouse->id)>{{ $warehouse->name }} ({{ $warehouse->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="partProductionReportPart" class="form-label text-sm">Part</label>
                    <select id="partProductionReportPart" name="part_id" class="form-control form-control-sm js-s2">
                        <option value="">All parts</option>
                        @foreach ($parts as $part)
                            <option value="{{ $part->id }}" @selected((int) $selectedPartId === $part->id)>{{ $part->name }} ({{ $part->part_code }})</option>
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
            ['label' => 'Planned Quantity', 'value' => $report['planned'], 'icon' => 'mdi:clipboard-text-outline', 'variant' => 'opening', 'value_class' => 'text-primary-main', 'precision' => 3],
            ['label' => 'Good Quantity', 'value' => $report['good'], 'icon' => 'mdi:check-circle-outline', 'variant' => 'debit', 'value_class' => 'text-success-main', 'precision' => 3],
            ['label' => 'Reject Quantity', 'value' => $report['reject'], 'icon' => 'mdi:close-circle-outline', 'variant' => 'credit', 'value_class' => 'text-danger-main', 'precision' => 3],
            ['label' => 'Total Batch Cost', 'value' => $report['total_cost'], 'icon' => 'mdi:cash-register', 'variant' => 'closing', 'value_class' => 'text-primary-main', 'precision' => 2],
        ];
    @endphp
    <div class="row g-2 mb-24">
        @foreach ($summaryCards as $card)
            <div class="col-xl-3 col-md-6">
                <div class="card h-100 account-ledger-summary-card account-ledger-summary-card--{{ $card['variant'] }}">
                    <div class="card-body d-flex align-items-center justify-content-between gap-2 py-16">
                        <div>
                            <small class="text-muted d-block mb-6">{{ $card['label'] }}</small>
                            <h6 class="mb-0 fw-bold {{ $card['value_class'] }}">{{ number_format((float) $card['value'], $card['precision']) }}</h6>
                        </div>
                        <iconify-icon icon="{{ $card['icon'] }}" class="text-primary-main" style="font-size:28px; line-height:1; flex-shrink:0"></iconify-icon>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">Completed Production Batches</h5>
            <div class="actions-bar d-flex align-items-center gap-2">
                <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')</ul>
            </div>
        </div>
        <div class="card-body">
            <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="monthlyPartProductionTable" style="width:100%">
                <thead>
                    <tr>
                        <th style="width:65px">S.L</th>
                        <th>Batch</th>
                        <th>Part</th>
                        <th>Location</th>
                        <th class="text-end">Planned</th>
                        <th class="text-end">Good</th>
                        <th class="text-end">Reject</th>
                        <th>QC</th>
                        <th class="text-end">Material Cost</th>
                        <th class="text-end">Direct Cost</th>
                        <th class="text-end">Total Cost</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
@endsection

@section('script')
    <script>
        var DATATABLE_URL = @json(route('reporting.monthly-part-production.list.ajax'));
        S2.auto($('.card-body'));
        $(document).on('preXhr.dt', '#monthlyPartProductionTable', function (event, settings, data) {
            Object.assign(data, {
                period_id: $('#partProductionReportPeriod').val(),
                branch_id: $('#partProductionReportBranch').val(),
                warehouse_id: $('#partProductionReportWarehouse').val(),
                part_id: $('#partProductionReportPart').val()
            });
        });
        $(document).on('click', '#resetMonthlyPartProductionFilters', function () {
            $('#partProductionReportWarehouse').val('').trigger('change');
            $('#partProductionReportPart').val('').trigger('change');
            @if (! $fixedBranchId)
                $('#partProductionReportBranch').val('').trigger('change');
            @endif
            window.location = @json(route('reporting.monthly-part-production.index'));
        });
    </script>
@endsection
