@extends('backend.layouts.master')

@section('meta')
    <title>Low-Stock Alerts</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Low-Stock Alerts</h6>
                <x-backend.page-note title="How low-stock alerts work" label="View low-stock rules" align="start">
                    An alert appears when a warehouse item's current base-unit stock is equal to or below its configured minimum level.
                    This report recommends Purchase, Part Production or Finished Product Assembly but never creates or posts a document automatically.
                </x-backend.page-note>
            </div>
            <p class="text-muted mb-0">Review warehouse shortages before starting Purchase or Production planning.</p>
        </div>
        <ul class="d-flex align-items-center gap-2 mb-0">
            <li class="fw-medium">
                <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Dashboard
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Supply Planning</li>
            <li>-</li>
            <li class="fw-medium">Low-Stock Alerts</li>
        </ul>
    </div>

    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <h5 class="card-title mb-0">Alert Filters</h5>
            <button type="button" id="resetLowStockFilters"
                    class="btn btn-outline-secondary btn-sm px-12 py-8 radius-8 app-action-button">
                <iconify-icon icon="mdi:filter-remove-outline"></iconify-icon>
                <span>Reset Filters</span>
            </button>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('planning.low-stock.index') }}" class="row gy-3 align-items-end">
                <div class="col-xl-2 col-md-4">
                    <label for="lowStockDomain" class="form-label text-sm">Inventory Type</label>
                    <select id="lowStockDomain" name="domain" class="form-control form-control-sm js-s2">
                        <option value="">All types</option>
                        <option value="RAW_MATERIAL" @selected($filters['domain'] === 'RAW_MATERIAL')>Raw Material</option>
                        <option value="PART" @selected($filters['domain'] === 'PART')>Part</option>
                        <option value="FINISHED_PRODUCT" @selected($filters['domain'] === 'FINISHED_PRODUCT')>Finished Product</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="lowStockStatus" class="form-label text-sm">Alert Status</label>
                    <select id="lowStockStatus" name="alert_status" class="form-control form-control-sm js-s2">
                        <option value="">All alerts</option>
                        <option value="OUT_OF_STOCK" @selected($filters['alert_status'] === 'OUT_OF_STOCK')>Out of Stock</option>
                        <option value="LOW_STOCK" @selected($filters['alert_status'] === 'LOW_STOCK')>Low Stock</option>
                        <option value="REORDER_POINT" @selected($filters['alert_status'] === 'REORDER_POINT')>At Reorder Point</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="lowStockBranch" class="form-label text-sm">Branch</label>
                    <select id="lowStockBranch" name="branch_id" class="form-control form-control-sm js-s2" @disabled($fixedBranchId)>
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) $filters['branch_id'] === (int) $branch->id)>
                                {{ $branch->name }} ({{ $branch->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-4 col-md-6">
                    <label for="lowStockWarehouse" class="form-label text-sm">Warehouse</label>
                    <select id="lowStockWarehouse" name="warehouse_id" class="form-control form-control-sm js-s2">
                        <option value="">All warehouses</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}"
                                    data-branch-id="{{ $warehouse->branch_id }}"
                                    @selected((int) $filters['warehouse_id'] === (int) $warehouse->id)>
                                {{ $warehouse->name }} ({{ $warehouse->code }}) — {{ $warehouse->branch_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-3">
                    <button type="submit"
                            class="btn btn-primary app-primary-action w-100 d-inline-flex align-items-center justify-content-center gap-1 px-14 py-8 radius-8">
                        <iconify-icon icon="mdi:magnify"></iconify-icon>
                        <span>Apply Filters</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    @php
        $summaryCards = [
            ['label' => 'Total Alerts', 'value' => $summary['total_alerts'], 'icon' => 'mdi:alert-decagram-outline', 'variant' => 'opening', 'value_class' => 'text-primary-main'],
            ['label' => 'Out of Stock', 'value' => $summary['out_of_stock'], 'icon' => 'mdi:package-variant-remove', 'variant' => 'credit', 'value_class' => 'text-danger-main'],
            ['label' => 'Low Stock', 'value' => $summary['low_stock'], 'icon' => 'mdi:package-variant-minus', 'variant' => 'closing', 'value_class' => 'text-warning-main'],
            ['label' => 'At Reorder Point', 'value' => $summary['reorder_point'], 'icon' => 'mdi:target', 'variant' => 'debit', 'value_class' => 'text-info-main'],
        ];
    @endphp
    <div class="row g-2 mb-24">
        @foreach ($summaryCards as $card)
            <div class="col-xl-3 col-md-6">
                <div class="card h-100 account-ledger-summary-card account-ledger-summary-card--{{ $card['variant'] }}">
                    <div class="card-body d-flex align-items-center justify-content-between gap-2 py-16">
                        <div>
                            <small class="text-muted d-block mb-6">{{ $card['label'] }}</small>
                            <h6 class="mb-0 fw-bold {{ $card['value_class'] }}">{{ number_format($card['value']) }}</h6>
                        </div>
                        <iconify-icon icon="{{ $card['icon'] }}" class="{{ $card['value_class'] }}"
                                      style="font-size:28px; line-height:1; flex-shrink:0"></iconify-icon>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card basic-data-table">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">Warehouse Low-Stock Register</h5>
            <div class="actions-bar d-flex align-items-center gap-2">
                <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
                    @include('backend.include.buttons')
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="lowStockTable"
                       class="table bordered-table table-scroll mb-0 align-middle AjaxDataTable"
                       data-has-action-column="false"
                       style="width:100%">
                    <thead>
                        <tr>
                            <th style="width:65px">S.L</th>
                            <th>Alert</th>
                            <th>Inventory</th>
                            <th>Item</th>
                            <th>Location</th>
                            <th class="text-end">Current Stock</th>
                            <th class="text-end">Minimum Level</th>
                            <th class="text-end">Shortfall</th>
                            <th class="text-end">Default Reorder</th>
                            <th class="text-end">Maximum Target</th>
                            <th class="text-end">Suggested Qty</th>
                            <th>Suggested Action</th>
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
        var DATATABLE_URL = @json(route('planning.low-stock.list.ajax'));

        S2.auto($('.card-body'));

        $(document).on('preXhr.dt', '#lowStockTable', function(event, settings, data) {
            Object.assign(data, {
                domain: $('#lowStockDomain').val(),
                alert_status: $('#lowStockStatus').val(),
                branch_id: $('#lowStockBranch').val(),
                warehouse_id: $('#lowStockWarehouse').val()
            });
        });

        $('#lowStockBranch').on('change', function() {
            const branchId = String($(this).val() || '');
            const warehouse = $('#lowStockWarehouse');
            let selectedWarehouseIsAvailable = true;

            warehouse.find('option').each(function() {
                const option = $(this);
                const optionBranchId = String(option.data('branch-id') || '');
                const isAvailable = option.val() === '' || branchId === '' || optionBranchId === branchId;

                option.prop('disabled', !isAvailable);
                if (option.is(':selected') && !isAvailable) {
                    selectedWarehouseIsAvailable = false;
                }
            });

            if (!selectedWarehouseIsAvailable) {
                warehouse.val('').trigger('change');
            } else {
                warehouse.trigger('change.select2');
            }
        });

        $('#lowStockBranch').trigger('change');

        $('#resetLowStockFilters').on('click', function() {
            window.location = @json(route('planning.low-stock.index'));
        });
    </script>
@endsection
