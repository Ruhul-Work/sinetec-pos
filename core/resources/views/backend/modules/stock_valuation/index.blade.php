@extends('backend.layouts.master')

@section('meta')
    <title>Stock Valuation</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Monthly Stock Valuation</h6>
                <x-backend.page-note title="Valuation rule" label="View valuation rule" align="start">
                    Current positive stock balances are multiplied by the selected reporting period's Approved or Locked monthly rate.
                    Existing stock movements and document prices are not changed.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Branch and warehouse-wise inventory value using the period rate sheet.</p>
        </div>
    </div>

    <div class="card mb-24">
        <div class="card-header"><h5 class="card-title mb-0">Valuation Filters</h5></div>
        <div class="card-body">
            <form method="GET" action="{{ route('reporting.stock-valuation.index') }}" class="row gy-3 align-items-end">
                <div class="col-xl-3 col-md-4">
                    <label for="valuationPeriod" class="form-label text-sm">Reporting Period</label>
                    <select id="valuationPeriod" name="period_id" class="form-control form-control-sm js-s2" required>
                        @foreach ($periods as $period)
                            <option value="{{ $period->id }}" @selected((int) $selectedPeriodId === $period->id)>
                                {{ sprintf('%04d-%02d', $period->year, $period->month) }} · {{ $period->status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="valuationDomain" class="form-label text-sm">Inventory Type</label>
                    <select id="valuationDomain" name="domain" class="form-control form-control-sm js-s2">
                        <option value="">All types</option>
                        <option value="raw_material" @selected($selectedDomain === 'raw_material')>Raw Material</option>
                        <option value="part" @selected($selectedDomain === 'part')>Part</option>
                        <option value="finished_product" @selected($selectedDomain === 'finished_product')>Finished Product</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="valuationBranch" class="form-label text-sm">Branch</label>
                    <select id="valuationBranch" name="branch_id" class="form-control form-control-sm js-s2"
                            @disabled($fixedBranchId)>
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === $branch->id)>
                                {{ $branch->name }} ({{ $branch->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label for="valuationWarehouse" class="form-label text-sm">Warehouse</label>
                    <select id="valuationWarehouse" name="warehouse_id" class="form-control form-control-sm js-s2">
                        <option value="">All warehouses</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((int) $selectedWarehouseId === $warehouse->id)>
                                {{ $warehouse->name }} ({{ $warehouse->code }}) · {{ $warehouse->branch_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-3">
                    <button type="submit"
                            class="btn btn-primary app-primary-action w-100 d-inline-flex align-items-center justify-content-center gap-1 px-14 py-8 radius-8">
                        <iconify-icon icon="mdi:magnify"></iconify-icon>
                        <span>Run Valuation</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if (! $report['sheet'])
        <div class="alert alert-warning d-flex align-items-center gap-2">
            <iconify-icon icon="mdi:information-outline"></iconify-icon>
            <span>No Approved or Locked Monthly Rate Sheet is available for this period. Rows are shown at 0.0000 or should be reviewed after preparing the sheet.</span>
        </div>
    @else
        <div class="alert alert-{{ $report['sheet']->status->value === 'LOCKED' ? 'success' : 'info' }} d-flex align-items-center gap-2">
            <iconify-icon icon="{{ $report['sheet']->status->value === 'LOCKED' ? 'mdi:lock-outline' : 'mdi:check-decagram-outline' }}"></iconify-icon>
            <span>
                Using {{ $report['sheet']->status->label() }} sheet {{ $report['sheet']->sheet_no }}
                for {{ sprintf('%04d-%02d', $report['period']->year, $report['period']->month) }}.
            </span>
        </div>
    @endif

    @php
        $summaryCards = [
            ['label' => 'Raw Material Value', 'value' => $report['domain_totals']['raw_material']['value'], 'icon' => 'mdi:cube-outline', 'variant' => 'opening', 'value_class' => 'text-primary-main'],
            ['label' => 'Part Value', 'value' => $report['domain_totals']['part']['value'], 'icon' => 'mdi:puzzle-outline', 'variant' => 'debit', 'value_class' => 'text-success-main'],
            ['label' => 'Finished Product Value', 'value' => $report['domain_totals']['finished_product']['value'], 'icon' => 'mdi:package-variant-closed', 'variant' => 'closing', 'value_class' => 'text-primary-main'],
            ['label' => 'Total Stock Value', 'value' => $report['grand_total'], 'icon' => 'mdi:cash-register', 'variant' => 'credit', 'value_class' => 'text-warning-main'],
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
                        <iconify-icon icon="{{ $card['icon'] }}" class="text-primary-main"
                                      style="font-size:28px; line-height:1; flex-shrink:0"></iconify-icon>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0">Valuation Details</h5></div>
        <div class="card-body">
            @if ($report['rows']->isEmpty())
                <div class="text-center text-muted py-32">No positive stock balances found for the selected filters.</div>
            @else
                <div class="table-responsive">
                    <table class="table bordered-table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Inventory</th>
                                <th>Item</th>
                                <th>Location</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Monthly Rate</th>
                                <th class="text-end">Stock Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['rows'] as $row)
                                <tr>
                                    <td><span class="badge bg-light text-dark">{{ $row['domain_label'] }}</span></td>
                                    <td><strong>{{ $row['item_name'] }}</strong><br><code>{{ $row['item_code'] }}</code></td>
                                    <td><strong>{{ $row['warehouse_name'] }}</strong> <code>{{ $row['warehouse_code'] }}</code><br><small class="text-muted">{{ $row['branch_name'] }} ({{ $row['branch_code'] }})</small></td>
                                    <td class="text-end fw-semibold">{{ number_format((float) $row['quantity'], 6) }} {{ $row['unit_code'] }}</td>
                                    <td class="text-end">
                                        <strong>{{ number_format((float) $row['rate'], 2) }}</strong>
                                        <br><small class="{{ $row['basis'] === 'NO_APPROVED_RATE' ? 'text-danger-main' : 'text-muted' }}">{{ str_replace('_', ' ', $row['basis']) }}</small>
                                    </td>
                                    <td class="text-end fw-bold text-primary-main">{{ number_format((float) $row['value'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection

@section('script')
    <script>S2.auto($('.card-body'));</script>
@endsection
