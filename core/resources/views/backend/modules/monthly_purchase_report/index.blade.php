@extends('backend.layouts.master')

@section('meta')
    <title>Monthly Purchase Report</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Monthly Purchase Report</h6>
                <x-backend.page-note title="Report scope" label="View report rules" align="start">
                    This report lists posted Purchase Receipts and Returns within the selected reporting period.
                    Supplier payments are separate totals and do not reduce Purchase Receipt total.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Posted purchase activity for the selected month and branch scope.</p>
        </div>
    </div>

    <div class="card mb-24">
        <div class="card-header"><h5 class="card-title mb-0">Report Filters</h5></div>
        <div class="card-body">
            <form method="GET" action="{{ route('reporting.monthly-purchase.index') }}" class="row gy-3 align-items-end">
                <div class="col-xl-2 col-md-4">
                    <label for="purchaseReportPeriod" class="form-label text-sm">Reporting Period</label>
                    <select id="purchaseReportPeriod" name="period_id" class="form-control form-control-sm js-s2" required>
                        @foreach ($periods as $period)
                            <option value="{{ $period->id }}" @selected((int) $selectedPeriodId === $period->id)>
                                {{ sprintf('%04d-%02d', $period->year, $period->month) }} · {{ $period->status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="purchaseReportDomain" class="form-label text-sm">Inventory Type</label>
                    <select id="purchaseReportDomain" name="domain" class="form-control form-control-sm js-s2">
                        <option value="">All types</option>
                        <option value="RAW_MATERIAL" @selected($selectedDomain === 'RAW_MATERIAL')>Raw Material</option>
                        <option value="PART" @selected($selectedDomain === 'PART')>Part</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="purchaseReportBranch" class="form-label text-sm">Branch</label>
                    <select id="purchaseReportBranch" name="branch_id" class="form-control form-control-sm js-s2" @disabled($fixedBranchId)>
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === $branch->id)>
                                {{ $branch->name }} ({{ $branch->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="purchaseReportWarehouse" class="form-label text-sm">Warehouse</label>
                    <select id="purchaseReportWarehouse" name="warehouse_id" class="form-control form-control-sm js-s2">
                        <option value="">All warehouses</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((int) $selectedWarehouseId === $warehouse->id)>
                                {{ $warehouse->name }} ({{ $warehouse->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="purchaseReportSupplier" class="form-label text-sm">Supplier</label>
                    <select id="purchaseReportSupplier" name="supplier_id" class="form-control form-control-sm js-s2">
                        <option value="">All suppliers</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((int) $selectedSupplierId === $supplier->id)>
                                {{ $supplier->name }}{{ $supplier->code ? ' ('.$supplier->code.')' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
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
            ['label' => 'Purchase Receipts', 'value' => $report['receipt_total'], 'icon' => 'mdi:file-document-check-outline', 'variant' => 'opening', 'value_class' => 'text-primary-main'],
            ['label' => 'Purchase Returns', 'value' => $report['return_total'], 'icon' => 'mdi:file-document-remove-outline', 'variant' => 'credit', 'value_class' => 'text-danger-main'],
            ['label' => 'Net Purchase', 'value' => $report['net_purchase'], 'icon' => 'mdi:calculator-variant-outline', 'variant' => 'closing', 'value_class' => 'text-primary-main'],
            ['label' => 'Posted Payments', 'value' => $report['payment_total'], 'icon' => 'mdi:cash-check', 'variant' => 'debit', 'value_class' => 'text-success-main'],
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
        <div class="card-header"><h5 class="card-title mb-0">Purchased Item Lines</h5></div>
        <div class="card-body">
            @if ($report['lines']->isEmpty())
                <div class="text-center text-muted py-32">No posted purchase lines found in this period.</div>
            @else
                <div class="table-responsive">
                    <table class="table bordered-table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Type</th><th>Receipt</th><th>Item</th><th class="text-end">Quantity</th><th class="text-end">Unit Rate</th><th class="text-end">Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['lines'] as $line)
                                <tr>
                                    <td><span class="badge bg-light text-dark">{{ $line->domain === 'PART' ? 'Part' : 'Raw Material' }}</span></td>
                                    <td><strong>{{ $line->receipt_no }}</strong><br><small class="text-muted">{{ \Illuminate\Support\Carbon::parse($line->receipt_date)->format('d M Y') }}</small></td>
                                    <td><strong>{{ $line->item_name }}</strong><br><code>{{ $line->item_code }}</code></td>
                                    <td class="text-end fw-semibold">{{ number_format((float) $line->base_quantity, 6) }} {{ $line->unit_code }}</td>
                                    <td class="text-end">{{ number_format((float) $line->unit_rate, 2) }}</td>
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
                <div class="card-header"><h5 class="card-title mb-0">Posted Returns</h5></div>
                <div class="card-body">
                    @if ($report['returns']->isEmpty())
                        <div class="text-muted text-sm">No posted purchase returns.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table bordered-table mb-0 align-middle">
                                <thead><tr><th>Return</th><th>Date</th><th>Supplier</th><th class="text-end">Amount</th></tr></thead>
                                <tbody>
                                    @foreach ($report['returns'] as $return)
                                        <tr>
                                            <td class="fw-semibold">{{ $return->return_no }}</td>
                                            <td>{{ \Illuminate\Support\Carbon::parse($return->return_date)->format('d M Y') }}</td>
                                            <td>{{ $return->supplier_name }}</td>
                                            <td class="text-end fw-bold text-danger-main">{{ number_format((float) $return->total, 2) }}</td>
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
                <div class="card-header"><h5 class="card-title mb-0">Posted Supplier Payments</h5></div>
                <div class="card-body">
                    @if ($report['payments']->isEmpty())
                        <div class="text-muted text-sm">No posted supplier payments.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table bordered-table mb-0 align-middle">
                                <thead><tr><th>Payment</th><th>Date</th><th>Supplier</th><th class="text-end">Amount</th></tr></thead>
                                <tbody>
                                    @foreach ($report['payments'] as $payment)
                                        <tr>
                                            <td class="fw-semibold">{{ $payment->payment_no }}</td>
                                            <td>{{ \Illuminate\Support\Carbon::parse($payment->payment_date)->format('d M Y') }}</td>
                                            <td>{{ $payment->supplier_name }}</td>
                                            <td class="text-end fw-bold text-success-main">{{ number_format((float) $payment->amount, 2) }}</td>
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
    <script>S2.auto($('.card-body'));</script>
@endsection
