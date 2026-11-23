@extends('backend.layouts.master')

@section('meta')
    <title>Final Monthly Snapshots</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Final Monthly Snapshots</h6>
                <x-backend.page-note title="Historical integrity" label="View snapshot rules" align="start">
                    One package is generated per scope and closed month after its Rate Sheet is locked.
                    A branch package contains only that branch's data; company scope contains every branch.
                    It contains Stock Valuation, Purchase, Stock, Part Production, Assembly and Sales data.
                    A correction requires period reopen/close and creates a new version without deleting the old one.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Freeze final monthly results and retain an auditable version history.</p>
        </div>
        <ul class="d-flex align-items-center gap-2 mb-0">
            <li class="fw-medium">
                <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Dashboard
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Final Monthly Snapshots</li>
        </ul>
    </div>

    <div class="alert alert-info d-flex align-items-center gap-2 mb-24">
        <iconify-icon icon="mdi:source-branch"></iconify-icon>
        <span>
            Current snapshot scope: <strong>{{ $scopeLabel }}</strong>.
            {{ $scopeBranch ? 'Only this branch data will be frozen.' : 'All branch data will be frozen together.' }}
        </span>
    </div>

    <div class="card basic-data-table snapshot-register-card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <h5 class="card-title mb-0">Monthly Snapshot Register</h5>
            <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-light text-dark">One package per scope / month</span>
                <div class="search-set me-2">
                    <div id="tableSearch" class="search-input"></div>
                </div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
                    @include('backend.include.buttons')
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="monthlySnapshotRegisterTable"
                       class="table bordered-table table-scroll mb-0 align-middle AjaxDataTable"
                       style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="width: 65px;">S.L</th>
                            <th>Period</th>
                            <th>Period Status</th>
                            <th>Rate Sheet</th>
                            <th>Current Snapshot</th>
                            <th>Generated</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    @foreach ($registerRows->where('can_create', true) as $registerRow)
        @php
            $period = $registerRow['period'];
            $current = $registerRow['current_snapshot'];
        @endphp

        <div class="modal fade" id="snapshotModal{{ $period->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="{{ route('reporting.snapshots.store', $period) }}">
                        @csrf
                        <div class="modal-header">
                            <div>
                                <h6 class="modal-title mb-1">
                                    {{ $current ? 'Generate Corrected Snapshot' : 'Generate Final Snapshot' }}
                                </h6>
                                <small class="text-muted">
                                    {{ sprintf('%04d-%02d', $period->year, $period->month) }}
                                    &middot; {{ $scopeLabel }}
                                </small>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <label class="form-label" for="snapshotNote{{ $period->id }}">
                                {{ $current ? 'Correction Note *' : 'Snapshot Note' }}
                            </label>
                            <textarea id="snapshotNote{{ $period->id }}"
                                      name="note"
                                      rows="3"
                                      class="form-control"
                                      @required($current)
                                      placeholder="{{ $current ? 'Explain the authorised correction.' : 'Optional month-close note.' }}"></textarea>
                            <small class="text-muted d-block mt-2">
                                This freezes the six report sections for the selected month only. It does not post stock or Finance transactions.
                            </small>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit"
                                    class="btn btn-primary app-primary-action d-inline-flex align-items-center justify-content-center gap-1">
                                <iconify-icon icon="mdi:archive-lock-outline" class="text-lg lh-1"></iconify-icon>
                                <span>Generate Snapshot</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
@endsection

@section('script')
    <script>
        var DATATABLE_URL = @json(route('reporting.snapshots.list.ajax'));
    </script>
@endsection
