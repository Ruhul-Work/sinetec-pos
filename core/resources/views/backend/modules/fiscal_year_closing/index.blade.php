@extends('backend.layouts.master')

@section('meta')
    <title>Fiscal Year Closing</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Fiscal Year Closing</h6>
                <x-backend.page-note title="Closing workflow" label="View closing rule" align="start">
                    This module closes one Owner/Partner Drawings balance into its Capital account
                    at the active fiscal year end. It creates a balanced Closing Journal Draft;
                    accountant approval and Journal posting are required before activating the next year.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Year-end Drawings to Capital adjustment by fiscal year and branch</p>
        </div>

        @perm('finance.fiscal-year-closings.create', 'add')
            <button
                type="button"
                class="btn btn-primary app-primary-action d-inline-flex align-items-center gap-1 px-14 py-9 radius-8 AjaxModal"
                data-ajax-modal="{{ route('finance.fiscal-year-closings.create') }}"
                data-size="lg"
                data-onsuccess="FiscalYearClosings.onSaved"
            >
                <iconify-icon icon="mdi:calendar-check-outline"></iconify-icon>
                <span>Prepare Year Closing</span>
            </button>
        @endperm
    </div>

    <div class="card basic-data-table">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">Closing Documents</h5>
            <div class="actions-bar d-flex align-items-center gap-2">
                <div class="search-set me-2">
                    <div id="tableSearch" class="search-input"></div>
                </div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
                    @include('backend.include.buttons')
                </ul>
            </div>
        </div>
        <div class="card-body">
            <table
                class="table bordered-table table-scroll mb-0 AjaxDataTable"
                id="fiscalYearClosingTable"
                style="width: 100%"
            >
                <thead>
                    <tr>
                        <th>S.L</th>
                        <th>Closing No</th>
                        <th>Fiscal Year</th>
                        <th>Closing Date</th>
                        <th>Branch</th>
                        <th>Drawings</th>
                        <th>Capital</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
@endsection

@section('script')
    <script>
        var DATATABLE_URL = @json(route('finance.fiscal-year-closings.list.ajax'));

        window.FiscalYearClosings = {
            onSaved: function (response) {
                if (response && response.redirect_url) {
                    window.location.href = response.redirect_url;
                }
            },
        };
    </script>
@endsection
