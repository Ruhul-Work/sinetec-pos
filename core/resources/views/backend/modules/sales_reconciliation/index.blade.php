@extends('backend.layouts.master')

@section('meta')
    <title>Sales Reconciliation</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Sales / Return Reconciliation</h6>
                <x-backend.page-note title="Reconciliation rules" label="View report rules" align="start">
                    This read-only report compares posted Sales and Returns with their Finished Product stock movements,
                    payment and due totals, and posted balanced Finance journals. It does not repair or change records.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Review posted sales activity and related collections/returns for the selected date range.</p>
        </div>
        <ul class="d-flex align-items-center gap-2 mb-0">
            <li class="fw-medium">
                <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Dashboard
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Sales Reconciliation</li>
        </ul>
    </div>

    <div class="card mb-24">
        <div class="card-header">
            <h5 class="card-title mb-0">Report Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('sales.reconciliation.index') }}" class="row gy-3 align-items-end">
                <div class="col-xl-4 col-md-6">
                    <label class="form-label text-sm" for="reconciliationBranch">Branch</label>
                    <select id="reconciliationBranch" name="branch_id" class="form-control js-s2"
                            @disabled($fixedBranchId)>
                        <option value="">All available branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) $selectedBranchId === (string) $branch->id)>
                                {{ $branch->name }} ({{ $branch->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label class="form-label text-sm" for="reconciliationDateFrom">From</label>
                    <input type="date" id="reconciliationDateFrom" name="date_from" class="form-control"
                           value="{{ $dateFrom }}" required>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label class="form-label text-sm" for="reconciliationDateTo">To</label>
                    <input type="date" id="reconciliationDateTo" name="date_to" class="form-control"
                           value="{{ $dateTo }}" required>
                </div>
                <div class="col-xl-2 col-md-6">
                    <button type="submit" class="btn btn-primary w-100 d-inline-flex align-items-center justify-content-center gap-1">
                        <iconify-icon icon="mdi:magnify"></iconify-icon>
                        <span>Run Report</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-24">
        @foreach ([
            ['Sales Checked', $report['sales_checked'], 'text-primary-main'],
            ['Sales With Issues', $report['sales_with_issues'], $report['sales_with_issues'] ? 'text-danger-main' : 'text-success-main'],
            ['Returns Checked', $report['returns_checked'], 'text-primary-main'],
            ['Returns With Issues', $report['returns_with_issues'], $report['returns_with_issues'] ? 'text-danger-main' : 'text-success-main'],
        ] as [$label, $value, $valueClass])
            <div class="col-xl-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <small class="text-muted d-block mb-1">{{ $label }}</small>
                        <h5 class="mb-0 fw-bold {{ $valueClass }}">{{ number_format($value) }}</h5>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card basic-data-table">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <div>
                <h5 class="card-title mb-0">Documents Requiring Review</h5>
                <small class="text-muted">Only mismatches are listed. Open a document to investigate; this report makes no changes.</small>
            </div>
            <span class="badge {{ count($report['issues']) ? 'bg-danger-focus text-danger-main' : 'bg-success-focus text-success-main' }} px-12 py-7 radius-6">
                {{ count($report['issues']) ? 'Review required' : 'No mismatches found' }}
            </span>
        </div>
        <div class="card-body">
            @if ($report['issues'])
                <div class="table-responsive">
                    <table class="table bordered-table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Document</th>
                                <th>Date</th>
                                <th>Related Sale</th>
                                <th>Mismatch</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['issues'] as $issue)
                                <tr>
                                    <td>{{ $issue['type'] }}</td>
                                    <td><a href="{{ $issue['url'] }}" class="fw-semibold">{{ $issue['number'] }}</a></td>
                                    <td>{{ \Illuminate\Support\Carbon::parse($issue['date'])->format('d M Y') }}</td>
                                    <td>{{ $issue['related_sale'] ?: '—' }}</td>
                                    <td>
                                        <ul class="mb-0 ps-3">
                                            @foreach ($issue['issues'] as $message)
                                                <li>{{ $message }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-32">
                    <iconify-icon icon="mdi:check-decagram-outline" class="text-success-main" style="font-size: 32px"></iconify-icon>
                    <h6 class="mt-2 mb-1">No reconciliation issues</h6>
                    <p class="text-muted mb-0">
                        {{ $report['sales_checked'] + $report['returns_checked'] ? 'All posted documents in this scope match the checked records.' : 'No posted Sales or Returns were found in this scope.' }}
                    </p>
                </div>
            @endif
        </div>
    </div>
@endsection

@section('script')
    <script>
        S2.auto($('.card-body'));
    </script>
@endsection
