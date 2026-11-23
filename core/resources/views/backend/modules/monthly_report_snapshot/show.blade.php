@extends('backend.layouts.master')

@section('meta')
    <title>Monthly Snapshot {{ sprintf('%04d-%02d', $snapshot->period->year, $snapshot->period->month) }}</title>
@endsection

@section('content')
    @php
        $reports = $report['reports'];
        $valuation = $reports['stock_valuation'];
        $purchase = $reports['purchase'];
        $stock = $reports['stock'];
        $partProduction = $reports['part_production'];
        $assembly = $reports['assembly'];
        $sales = $reports['sales'];
        $summaryCards = [
            ['label' => 'Stock Value', 'value' => $valuation['grand_total'], 'icon' => 'mdi:warehouse', 'variant' => 'opening'],
            ['label' => 'Net Purchase', 'value' => $purchase['net_purchase'], 'icon' => 'mdi:cart-arrow-down', 'variant' => 'debit'],
            ['label' => 'Part Production Cost', 'value' => $partProduction['total_cost'], 'icon' => 'mdi:factory', 'variant' => 'opening'],
            ['label' => 'Assembly Cost', 'value' => $assembly['total_cost'], 'icon' => 'mdi:package-variant-closed', 'variant' => 'debit'],
            ['label' => 'Net Sales', 'value' => $sales['net_sales'], 'icon' => 'mdi:chart-line', 'variant' => 'closing'],
            ['label' => 'Outstanding Due', 'value' => $sales['due_total'], 'icon' => 'mdi:account-cash-outline', 'variant' => 'credit'],
        ];
    @endphp

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h6 class="fw-semibold mb-0">
                    Final Monthly Snapshot · {{ sprintf('%04d-%02d', $snapshot->period->year, $snapshot->period->month) }}
                </h6>
                <span class="badge bg-info-focus text-info-main">
                    {{ $snapshot->branch
                        ? $snapshot->branch->name.' ('.$snapshot->branch->code.')'
                        : 'Company-wide' }}
                </span>
                <span class="badge {{ $isCurrent ? 'bg-success-focus text-success-main' : 'bg-secondary-focus text-secondary-main' }}">
                    Version {{ $snapshot->version }} · {{ $snapshot->status->label() }}
                </span>
            </div>
            <p class="text-muted m-0">
                Generated {{ $snapshot->generated_at->format('d M Y, h:i A') }} by {{ $snapshot->generator?->name ?: 'System' }}
            </p>
        </div>
        <a href="{{ route('reporting.snapshots.index') }}"
           class="btn btn-outline-secondary app-action-button d-inline-flex align-items-center justify-content-center gap-1">
            <iconify-icon icon="mdi:arrow-left" class="text-lg lh-1"></iconify-icon>
            <span>Back to snapshots</span>
        </a>
    </div>

    @unless ($isCurrent)
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-24">
            <iconify-icon icon="mdi:history"></iconify-icon>
            <span>This is a superseded historical version. Its stored data remains unchanged for audit purposes.</span>
        </div>
    @endunless

    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <h5 class="card-title mb-0">Snapshot Filters</h5>
            <span class="text-muted text-sm">Filters use frozen rows; live transaction tables are not queried.</span>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('reporting.snapshots.show', $snapshot) }}" class="row gy-3 align-items-end">
                <div class="col-lg-4 col-md-6">
                    <label for="snapshotBranch" class="form-label text-sm">Branch</label>
                    <select id="snapshotBranch" name="branch_id" class="form-control form-control-sm js-s2" @disabled($fixedBranchId)>
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch['id'] }}" @selected((int) $selectedBranchId === (int) $branch['id'])>
                                {{ $branch['name'] }} ({{ $branch['code'] }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-4 col-md-6">
                    <label for="snapshotWarehouse" class="form-label text-sm">Warehouse</label>
                    <select id="snapshotWarehouse" name="warehouse_id" class="form-control form-control-sm js-s2">
                        <option value="">All warehouses</option>
                        @foreach ($availableWarehouses as $warehouse)
                            <option value="{{ $warehouse['id'] }}" @selected((int) $selectedWarehouseId === (int) $warehouse['id'])>
                                {{ $warehouse['name'] }} ({{ $warehouse['code'] }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <button type="submit"
                            class="btn btn-primary btn-sm app-primary-action w-100 d-inline-flex align-items-center justify-content-center gap-1">
                        <iconify-icon icon="mdi:filter-outline" class="text-lg lh-1"></iconify-icon>
                        <span>Apply Filter</span>
                    </button>
                </div>
                <div class="col-lg-2 col-md-6">
                    <a href="{{ route('reporting.snapshots.show', $snapshot) }}"
                       class="btn btn-outline-secondary btn-sm w-100 app-action-button d-inline-flex align-items-center justify-content-center gap-1">
                        <iconify-icon icon="mdi:filter-remove-outline" class="text-lg lh-1"></iconify-icon>
                        <span>Reset</span>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-2 mb-24">
        @foreach ($summaryCards as $card)
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 account-ledger-summary-card account-ledger-summary-card--{{ $card['variant'] }}">
                    <div class="card-body d-flex align-items-center justify-content-between gap-2 py-16">
                        <div>
                            <small class="text-muted d-block mb-6">{{ $card['label'] }}</small>
                            <h6 class="mb-0 fw-bold">{{ number_format((float) $card['value'], 2) }}</h6>
                        </div>
                        <iconify-icon icon="{{ $card['icon'] }}" class="text-primary-main" style="font-size:25px"></iconify-icon>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card mb-24">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <ul class="nav nav-pills gap-2 snapshot-report-tabs" role="tablist">
                @foreach ([
                    'valuation' => 'Stock Valuation',
                    'purchase' => 'Purchase',
                    'stock' => 'Stock',
                    'part-production' => 'Part Production',
                    'assembly' => 'Assembly',
                    'sales' => 'Sales',
                ] as $tab => $label)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link {{ $loop->first ? 'active' : '' }}"
                                data-bs-toggle="tab"
                                data-bs-target="#snapshot-{{ $tab }}"
                                type="button"
                                role="tab">
                            {{ $label }}
                        </button>
                    </li>
                @endforeach
            </ul>

            @include('backend.modules.monthly_report_snapshot.partials.table_toolbar')
        </div>
        <div class="card-body">
            <div class="tab-content">
                <div class="tab-pane fade show active" id="snapshot-valuation" role="tabpanel">
                    <div class="snapshot-report-table-shell">
                        <div class="table-responsive">
                        <table id="snapshotValuationTable"
                               class="table bordered-table table-scroll align-middle mb-0 AjaxDataTable snapshot-report-table"
                               data-section="valuation"
                               data-has-action-column="false"
                               style="width: 100%;">
                            <thead>
                                <tr>
                                    <th style="width: 65px;">S.L</th>
                                    <th>Inventory</th>
                                    <th>Item</th>
                                    <th>Location</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Rate</th>
                                    <th class="text-end">Value</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="snapshot-purchase" role="tabpanel">
                    <div class="d-flex flex-wrap gap-3 mb-16 text-sm">
                        <span><strong>Receipts:</strong> {{ number_format((float) $purchase['receipt_total'], 2) }}</span>
                        <span><strong>Returns:</strong> {{ number_format((float) $purchase['return_total'], 2) }}</span>
                        <span><strong>Payments:</strong> {{ number_format((float) $purchase['payment_total'], 2) }}</span>
                    </div>
                    <div class="snapshot-report-table-shell">
                        <div class="table-responsive">
                        <table id="snapshotPurchaseTable"
                               class="table bordered-table table-scroll align-middle mb-0 AjaxDataTable snapshot-report-table"
                               data-section="purchase"
                               data-has-action-column="false"
                               style="width: 100%;">
                            <thead>
                                <tr>
                                    <th style="width: 65px;">S.L</th>
                                    <th>Receipt</th>
                                    <th>Type</th>
                                    <th>Item</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Rate</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="snapshot-stock" role="tabpanel">
                    <div class="snapshot-report-table-shell">
                        <div class="table-responsive">
                        <table id="snapshotStockTable"
                               class="table bordered-table table-scroll align-middle mb-0 AjaxDataTable snapshot-report-table"
                               data-section="stock"
                               data-has-action-column="false"
                               style="width: 100%;">
                            <thead>
                                <tr>
                                    <th style="width: 65px;">S.L</th>
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

                <div class="tab-pane fade" id="snapshot-part-production" role="tabpanel">
                    @include('backend.modules.monthly_report_snapshot.partials.production_table', [
                        'tableId' => 'snapshotPartProductionTable',
                        'section' => 'part-production',
                    ])
                </div>

                <div class="tab-pane fade" id="snapshot-assembly" role="tabpanel">
                    @include('backend.modules.monthly_report_snapshot.partials.production_table', [
                        'tableId' => 'snapshotAssemblyTable',
                        'section' => 'assembly',
                    ])
                </div>

                <div class="tab-pane fade" id="snapshot-sales" role="tabpanel">
                    <div class="d-flex flex-wrap gap-3 mb-16 text-sm">
                        <span><strong>Gross:</strong> {{ number_format((float) $sales['sale_total'], 2) }}</span>
                        <span><strong>Returns:</strong> {{ number_format((float) $sales['return_total'], 2) }}</span>
                        <span><strong>Collected:</strong> {{ number_format((float) $sales['net_collected'], 2) }}</span>
                        <span><strong>Due:</strong> {{ number_format((float) $sales['due_total'], 2) }}</span>
                    </div>
                    <div class="snapshot-report-table-shell">
                        <div class="table-responsive">
                        <table id="snapshotSalesTable"
                               class="table bordered-table table-scroll align-middle mb-0 AjaxDataTable snapshot-report-table"
                               data-section="sales"
                               data-has-action-column="false"
                               style="width: 100%;">
                            <thead>
                                <tr>
                                    <th style="width: 65px;">S.L</th>
                                    <th>Sale</th>
                                    <th>Channel</th>
                                    <th>Customer</th>
                                    <th>Location</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-end">Paid</th>
                                    <th class="text-end">Due</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <h5 class="card-title mb-0">Snapshot Integrity &amp; Version History</h5>
            <span class="badge d-inline-flex align-items-center gap-1 px-10 py-7 radius-6 {{ $checksumValid ? 'bg-success-focus text-success-main' : 'bg-danger-focus text-danger-main' }}">
                <iconify-icon icon="{{ $checksumValid ? 'mdi:shield-check-outline' : 'mdi:shield-alert-outline' }}"
                              class="text-lg lh-1"></iconify-icon>
                <span>{{ $checksumValid ? 'Payload verified' : 'Integrity check failed' }}</span>
            </span>
        </div>
        <div class="card-body">
            <div class="mb-16">
                <small class="text-muted d-block mb-1">SHA-256 checksum</small>
                <code class="text-break">{{ $snapshot->checksum }}</code>
            </div>
            @if ($snapshot->note)
                <p class="mb-16"><strong>Note:</strong> {{ $snapshot->note }}</p>
            @endif
            <div class="d-flex flex-wrap gap-2">
                @foreach ($history as $version)
                    <a href="{{ route('reporting.snapshots.show', $version) }}"
                       class="btn btn-sm snapshot-version-link {{ $version->is($snapshot) ? 'snapshot-version-link--selected' : 'snapshot-version-link--historical' }}">
                        Version {{ $version->version }} · {{ $version->status->label() }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
        var DATATABLE_URL = @json(route('reporting.snapshots.table.ajax', $snapshot));

        $(document).on('preXhr.dt', '.snapshot-report-table', function (event, settings, requestData) {
            requestData.section = $(this).data('section');
            requestData.branch_id = $('#snapshotBranch').val();
            requestData.warehouse_id = $('#snapshotWarehouse').val();
        });

        function activeSnapshotDataTable() {
            const $activeTable = $('.tab-pane.active .snapshot-report-table').first();

            return $activeTable.length ? $activeTable.DataTable() : null;
        }

        function bindSnapshotTableToolbar() {
            const $toolbar = $('.snapshot-report-toolbar');

            $toolbar.find('.snapshot-export-copy').on('click', function (event) {
                event.preventDefault();
                activeSnapshotDataTable()?.button('.buttons-copy').trigger();
            });

            $toolbar.find('.snapshot-export-excel').on('click', function (event) {
                event.preventDefault();
                activeSnapshotDataTable()?.button('.buttons-excel').trigger();
            });

            $toolbar.find('.snapshot-export-print').on('click', function (event) {
                event.preventDefault();
                activeSnapshotDataTable()?.button('.buttons-print').trigger();
            });

            $toolbar.find('.snapshot-export-refresh').on('click', function (event) {
                event.preventDefault();
                activeSnapshotDataTable()?.ajax.reload(null, false);
            });

            $toolbar.find('.snapshot-export-columns').on('click', function (event) {
                event.preventDefault();
                const dataTable = activeSnapshotDataTable();

                if (dataTable) {
                    openSnapshotColumnSelector(dataTable);
                }
            });
        }

        function openSnapshotColumnSelector(dataTable) {
            let options = '';

            dataTable.columns().every(function (index) {
                if (index === 0) {
                    return;
                }

                const title = $('<div>').text($(this.header()).text().trim()).html();
                const checked = this.visible() ? 'checked' : '';

                options += `
                    <div class="mb-1 px-3 py-2 text-start">
                        <input class="form-check-input snapshot-column-toggle"
                               type="checkbox"
                               id="snapshot_column_${index}"
                               data-index="${index}"
                               ${checked}>
                        <label class="form-check-label" for="snapshot_column_${index}">${title}</label>
                    </div>`;
            });

            Swal.fire({
                title: 'Show / Hide Columns',
                html: `<div style="max-height: 50vh; overflow: auto;">${options}</div>`,
                showCancelButton: true,
                confirmButtonText: 'Apply',
                cancelButtonText: 'Cancel',
                focusConfirm: false,
                preConfirm: function () {
                    document.querySelectorAll('.snapshot-column-toggle').forEach(function (checkbox) {
                        dataTable.column(Number(checkbox.dataset.index)).visible(checkbox.checked);
                    });
                },
            });
        }

        $(function () {
            bindSnapshotTableToolbar();
            S2.auto($('.card-body'));

            $('#snapshotBranch').on('change', function () {
                $('#snapshotWarehouse').val('').trigger('change');
            });

            $(document).on('shown.bs.tab', '[data-bs-toggle="tab"]', function () {
                const visibleTables = $.fn.dataTable.tables({
                    visible: true,
                    api: true,
                });

                visibleTables.columns.adjust();

                if (visibleTables.responsive && typeof visibleTables.responsive.recalc === 'function') {
                    visibleTables.responsive.recalc();
                }

            });
        });
    </script>
@endsection
